<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Actions\Cellar\AddCellarAdditionAction;
use App\Actions\Cellar\AddCellarAnalysisAction;
use App\Actions\Cellar\AddCellarProcessAction;
use App\Actions\Cellar\AddTastingNoteAction;
use App\Actions\Cellar\BlendIntoLotAction;
use App\Actions\Cellar\CreateTransferAction;
use App\Enums\CellarTransferType;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\User;
use App\Models\WineLot;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Undo for the cellar's reversible deletes — an addition, a reading, a
 * process, a tasting note, a transfer. Before the delete, the record's
 * create payload is kept for ten minutes under a token that only the same
 * person in the same workspace can use, once. Undo runs the same Action that
 * created it (an addition draws its stock again, a transfer moves the wine
 * again) and keeps the original date and author; if that's no longer
 * possible — the stock has gone, the vessel has filled — the Action's own
 * validation error says why.
 *
 * @phpstan-type Entry array{tenant_id: string, user_id: string, kind: string, lot_id: string, data: array<string, mixed>, at: string|null, by: string|null}
 */
class UndoStash
{
    public const TTL_SECONDS = 600;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AddCellarAdditionAction $addAddition,
        private readonly AddCellarAnalysisAction $addAnalysis,
        private readonly AddCellarProcessAction $addProcess,
        private readonly AddTastingNoteAction $addTasting,
        private readonly CreateTransferAction $createTransfer,
        private readonly BlendIntoLotAction $blendInto,
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

        $token = Str::random(40);
        Cache::put($this->key($token), [
            'tenant_id' => (string) $this->tenant->id(),
            'user_id' => $userId,
            'kind' => $kind,
            'lot_id' => (string) $lotId,
            'data' => $data,
            'at' => $record->created_at?->toIso8601String(),
            'by' => $record->created_by_id,
        ], self::TTL_SECONDS);

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

        return Cache::pull($this->key($token)) !== null ? $entry : null; // pull: a second click gets nothing
    }

    /**
     * Give a taken entry back (the restore failed, so it can be tried again).
     *
     * @param  Entry  $entry
     */
    public function giveBack(string $token, array $entry): void
    {
        Cache::put($this->key($token), $entry, self::TTL_SECONDS);
    }

    /**
     * Put the record back with the Action that created it.
     *
     * @param  Entry  $entry
     */
    public function restore(array $entry, string $userId): Model
    {
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

    private function key(string $token): string
    {
        return "cellar-undo:{$token}";
    }
}
