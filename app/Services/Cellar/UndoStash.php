<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Actions\Cellar\AddCellarAdditionAction;
use App\Actions\Cellar\AddCellarAnalysisAction;
use App\Actions\Cellar\AddCellarProcessAction;
use App\Actions\Cellar\AddTastingNoteAction;
use App\Actions\Cellar\BlendIntoLotAction;
use App\Actions\Cellar\CreateTransferAction;
use App\Actions\Tasks\UpdateWorkOrderStatusAction;
use App\Enums\CellarActivityStatus;
use App\Enums\CellarTransferType;
use App\Enums\TaskStatus;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\User;
use App\Models\WineLot;
use App\Models\WorkOrder;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Undo for the cellar's reversible deletes — an addition, a reading, a
 * process, a tasting note, a transfer — and for skipping or moving a task
 * on Today. Before the delete, the record's
 * create payload is kept for ten minutes under a token that only the same
 * person in the same workspace can use, once. Undo runs the same Action that
 * created it (an addition draws its stock again, a transfer moves the wine
 * again) and keeps the original date and author; if that's no longer
 * possible — the stock has gone, the vessel has filled — the Action's own
 * validation error says why. A skipped or moved task goes back to how it
 * was (planned, on its old date, its note as it was), and the round's board
 * card reopens if the skip had closed it. Today may send its undo much later
 * (it waits in the phone's queue for signal), so those last a day, and a
 * retried undo that already went through is answered as done.
 *
 * @phpstan-type Entry array{tenant_id: string, user_id: string, kind: string, lot_id: string, data: array<string, mixed>, at: string|null, by: string|null}
 */
class UndoStash
{
    public const TTL_SECONDS = 600;

    /**
     * A Today undo was decided within the toast's ten seconds, but the phone
     * may only get signal again hours later — the queued undo still counts.
     */
    public const ACTIVITY_TTL_SECONDS = 86400;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AddCellarAdditionAction $addAddition,
        private readonly AddCellarAnalysisAction $addAnalysis,
        private readonly AddCellarProcessAction $addProcess,
        private readonly AddTastingNoteAction $addTasting,
        private readonly CreateTransferAction $createTransfer,
        private readonly BlendIntoLotAction $blendInto,
        private readonly RoundWorkOrderSync $rounds,
        private readonly UpdateWorkOrderStatusAction $cardStatus,
    ) {}

    /**
     * Keep what it takes to put this record back; returns the token.
     */
    public function stash(CellarAddition|CellarAnalysis|CellarProcess|CellarTastingNote|CellarTransfer $record, string $userId): string
    {
        [$kind, $lotId, $data] = match (true) {
            $record instanceof CellarAddition => ['addition', $record->wine_lot_id, [
                'enological_product_id' => $record->enological_product_id,
                'vessel_id' => $record->vessel_id,
                'name' => $record->name,
                'category' => $record->category,
                'quantity' => (string) $record->quantity,
                'unit' => $record->unit,
                'cost_per_unit' => $record->cost_per_unit?->minor,
                'total_cost' => $record->total_cost?->minor,
                'note' => $record->note,
            ]],
            $record instanceof CellarAnalysis => ['analysis', $record->wine_lot_id, [
                'vessel_id' => $record->vessel_id,
                'date' => $record->date->toIso8601String(),
                'note' => $record->note,
                ...array_map(fn ($v) => $v === null ? null : (string) $v, $record->only(CellarAnalysis::PARAMETERS)),
            ]],
            $record instanceof CellarProcess => ['process', $record->wine_lot_id, [
                'vessel_id' => $record->vessel_id,
                'date' => $record->date->toIso8601String(),
                'kind' => $record->kind,
                'volume' => $record->volume !== null ? (string) $record->volume : null,
                'note' => $record->note,
            ]],
            $record instanceof CellarTastingNote => ['tasting', $record->wine_lot_id, [
                'vessel_id' => $record->vessel_id,
                'tasting_report_id' => $record->tasting_report_id,
                'date' => $record->date->toIso8601String(),
                'appearance' => $record->appearance,
                'nose' => $record->nose,
                'palate' => $record->palate,
                'overall' => $record->overall,
                'score' => $record->score,
                'note' => $record->note,
            ]],
            $record instanceof CellarTransfer => ['transfer', $record->from_lot_id, [
                'type' => $record->type->value,
                'volume_liters' => (string) $record->volume_liters,
                'to_lot_id' => $record->to_lot_id,
                'from_vessel_id' => $record->from_vessel_id,
                'to_vessel_id' => $record->to_vessel_id,
                'note' => $record->note,
            ]],
        };

        return $this->keep($kind, (string) $lotId, $data, $record->created_at?->toIso8601String(), $record->created_by_id, $userId);
    }

    /**
     * Keep a planned task as it is now, before Today skips or moves it.
     */
    public function stashActivity(CellarActivity $activity, string $userId): string
    {
        $card = null;
        if ($activity->round_id !== null) {
            $workOrderId = CellarActivityRound::query()->whereKey($activity->round_id)->value('work_order_id');
            $card = $workOrderId !== null ? WorkOrder::query()->whereKey($workOrderId)->first() : null;
        }

        return $this->keep('activity', (string) $activity->wine_lot_id, [
            'activity_id' => (string) $activity->getKey(),
            'planned_for' => $activity->planned_for->toDateString(),
            'note' => $activity->note,
            'card_id' => $card?->getKey(),
            'card_status' => $card?->status->value,
        ], null, null, $userId, self::ACTIVITY_TTL_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function keep(string $kind, string $lotId, array $data, ?string $at, ?string $by, string $userId, int $ttl = self::TTL_SECONDS): string
    {
        $token = Str::random(40);
        Cache::put($this->key($token), [
            'tenant_id' => (string) $this->tenant->id(),
            'user_id' => $userId,
            'kind' => $kind,
            'lot_id' => $lotId,
            'data' => $data,
            'at' => $at,
            'by' => $by,
        ], $ttl);

        return $token;
    }

    /**
     * Take the entry for this token — once, and only for the person who made
     * it, in the workspace they made it in. Null when it's unknown, used,
     * expired or someone else's.
     *
     * @return Entry|null
     */
    public function take(string $token, string $userId): ?array
    {
        /** @var Entry|null $entry */
        $entry = Cache::get($this->key($token));
        if ($entry === null || $entry['user_id'] !== $userId || $entry['tenant_id'] !== (string) $this->tenant->id()) {
            return null;
        }

        if (Cache::pull($this->key($token)) === null) {
            return null; // pull: a second click gets nothing
        }
        // Remember who used it, so a retried request (its answer was lost) can be told it already worked.
        Cache::put($this->usedKey($token), $userId, $this->ttl($entry['kind']));

        return $entry;
    }

    /** Whether this person already used this token (a retry of an undo that went through). */
    public function wasUsedBy(string $token, string $userId): bool
    {
        return Cache::get($this->usedKey($token)) === $userId;
    }

    /**
     * Give a taken entry back (the restore failed, so it can be tried again).
     *
     * @param  Entry  $entry
     */
    public function giveBack(string $token, array $entry): void
    {
        Cache::forget($this->usedKey($token));
        Cache::put($this->key($token), $entry, $this->ttl($entry['kind']));
    }

    /**
     * Put the record back with the Action that created it.
     *
     * @param  Entry  $entry
     */
    public function restore(array $entry, string $userId): Model
    {
        if ($entry['kind'] === 'activity') {
            return DB::transaction(fn (): Model => $this->restoreActivity($entry['data']));
        }

        return DB::transaction(function () use ($entry, $userId): Model {
            $lot = WineLot::query()->whereKey($entry['lot_id'])->firstOrFail();
            $by = $entry['by'] !== null && User::query()->whereKey($entry['by'])->exists() ? $entry['by'] : $userId;
            $data = $entry['data'];

            $record = match ($entry['kind']) {
                'addition' => $this->addAddition->execute($lot, $data, $by),
                'analysis' => $this->addAnalysis->execute($lot, $data, $by),
                'process' => $this->addProcess->execute($lot, $data, $by),
                'tasting' => $this->addTasting->execute($lot, $data, $by),
                default => $this->restoreTransfer($lot, $data, $by),
            };

            // Keep its place in the lot's history.
            if ($entry['at'] !== null) {
                $record->forceFill(['created_at' => $entry['at']])->saveQuietly();
            }

            return $record;
        });
    }

    /**
     * A blend from a vessel goes back through blend-in (it carries grapes
     * and cost); a rack, a split or a blend without vessels through the
     * plain transfer.
     *
     * @param  array<string, mixed>  $data
     */
    private function restoreTransfer(WineLot $from, array $data, string $by): CellarTransfer
    {
        $toLot = WineLot::query()->whereKey((string) $data['to_lot_id'])->firstOrFail();
        if ($data['type'] === CellarTransferType::Blend->value && ! empty($data['from_vessel_id']) && ! empty($data['to_vessel_id'])) {
            $this->blendInto->execute($toLot, [
                'from_vessel_id' => (string) $data['from_vessel_id'],
                'volume' => (string) $data['volume_liters'],
                'to_vessel_id' => (string) $data['to_vessel_id'],
                'note' => is_string($data['note'] ?? null) ? $data['note'] : null,
            ], $by);

            return CellarTransfer::query()
                ->where('from_lot_id', $from->getKey())
                ->where('to_lot_id', $toLot->getKey())
                ->orderByDesc('id')
                ->firstOrFail();
        }

        return $this->createTransfer->execute($from, $data, $by);
    }

    /**
     * Back to planned, on its old date, with its old note — unless it has
     * been done since.
     *
     * @param  array<string, mixed>  $data
     */
    private function restoreActivity(array $data): CellarActivity
    {
        /** @var CellarActivity $activity */
        $activity = CellarActivity::query()->whereKey((string) $data['activity_id'])->lockForUpdate()->firstOrFail();
        if ($activity->status === CellarActivityStatus::Done) {
            throw ValidationException::withMessages(['status' => __('It has been done since.')]);
        }

        $activity->forceFill([
            'status' => CellarActivityStatus::Planned,
            'planned_for' => (string) $data['planned_for'],
            'note' => $data['note'],
            'skip_reason' => null,
            'skipped_at' => null,
            'executed_by_id' => null,
        ])->save();

        // The skip may have closed the round's card (nothing left planned) — open it again.
        if (is_string($data['card_id'] ?? null) && is_string($data['card_status'] ?? null)) {
            $card = WorkOrder::query()->whereKey($data['card_id'])->first();
            $before = TaskStatus::tryFrom($data['card_status']);
            if ($card !== null && $before !== null && ! $before->isClosed() && $card->status === TaskStatus::Done) {
                $this->cardStatus->execute($card, $before);
            }
        }
        $this->rounds->sync($activity->round_id);

        return $activity;
    }

    private function ttl(string $kind): int
    {
        return $kind === 'activity' ? self::ACTIVITY_TTL_SECONDS : self::TTL_SECONDS;
    }

    private function usedKey(string $token): string
    {
        return "cellar-undo-used:{$token}";
    }

    private function key(string $token): string
    {
        return "cellar-undo:{$token}";
    }
}
