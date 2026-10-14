<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Actions\Tasks\CreateWorkOrderAction;
use App\Enums\CellarActivityStatus;
use App\Enums\CellarActivityType;
use App\Enums\WorkOrderCategory;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Models\WorkOrderBoard;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\ActivityPayload;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan cellar work. A non-empty `round_name` groups the items into a round and
 * mirrors the round as one CELLAR card on the default Work Orders board, so the
 * team sees it next to the rest of their tasks. Every item is validated up
 * front (payload shape, lot/vessel pairing) and everything is written in one
 * transaction — the prototype wrote round, card and items separately.
 */
class CreateCellarActivitiesAction
{
    public function __construct(
        private readonly ActivityPayload $payloads,
        private readonly CreateWorkOrderAction $createWorkOrder,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Each activity: {type, planned_for (Y-m-d), wine_lot_id?, vessel_id?, note?, sort_order?, payload}.
     *
     * @param  array{round_name?: string|null, assigned_to_id?: string|null, activities: list<array<string, mixed>>}  $data
     * @return array{round_id: string|null, work_order_id: string|null, count: int}
     */
    public function execute(array $data, string $createdById): array
    {
        $items = $this->prepare($data['activities']);
        $roundName = trim((string) ($data['round_name'] ?? ''));
        $assignee = $data['assigned_to_id'] ?? null;

        return DB::transaction(function () use ($items, $roundName, $assignee, $createdById): array {
            $round = null;
            $card = null;

            if ($roundName !== '') {
                $round = CellarActivityRound::create(['name' => $roundName, 'created_by_id' => $createdById]);

                $count = count($items);
                $card = $this->createWorkOrder->execute([
                    'title' => "🍇 {$roundName}",
                    'description' => $count.' planned cellar '.($count === 1 ? 'task' : 'tasks').' — execute them from Cellar › Today.',
                    'category' => WorkOrderCategory::Cellar,
                    'due_date' => $this->earliest($items),
                    'assignee_id' => $assignee,
                    'board_id' => WorkOrderBoard::defaultBoard()?->getKey(),
                ], $createdById);

                $round->work_order_id = $card->getKey();
                $round->save();
            }

            foreach ($items as $i => $item) {
                CellarActivity::create([
                    ...$item,
                    'status' => CellarActivityStatus::Planned,
                    'sort_order' => $item['sort_order'] ?? $i,
                    'round_id' => $round?->getKey(),
                    'assigned_to_id' => $assignee,
                    'planned_by_id' => $createdById,
                ]);
            }

            if ($round !== null) {
                $this->audit->record(Auth::user(), 'cellar_activity_round.planned', $round, [
                    'count' => count($items),
                    'work_order_id' => $card->getKey(),
                ]);
            }

            return [
                'round_id' => $round?->getKey(),
                'work_order_id' => $card?->getKey(),
                'count' => count($items),
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function earliest(array $items): string
    {
        $dates = array_map(fn (array $item): string => (string) $item['planned_for'], $items);
        sort($dates);

        return $dates[0] ?? now()->toDateString();
    }

    /**
     * @param  list<array<string, mixed>>  $activities
     * @return list<array<string, mixed>>
     */
    private function prepare(array $activities): array
    {
        if ($activities === []) {
            throw ValidationException::withMessages(['activities' => 'Plan at least one activity.']);
        }

        $prepared = [];
        foreach ($activities as $i => $raw) {
            $path = "activities.{$i}";
            $type = CellarActivityType::from((string) $raw['type']);
            /** @var array<string, mixed> $rawPayload */
            $rawPayload = (array) ($raw['payload'] ?? []);
            $payload = $this->payloads->validate($type, $rawPayload, "{$path}.payload");

            $lotId = isset($raw['wine_lot_id']) ? (string) $raw['wine_lot_id'] : null;
            $vesselId = isset($raw['vessel_id']) ? (string) $raw['vessel_id'] : null;

            if ($type === CellarActivityType::Transfer) {
                $vesselId = (string) $payload['from_vessel_id'];
            }

            if ($type !== CellarActivityType::Vineyard && $lotId === null) {
                throw ValidationException::withMessages(["{$path}.wine_lot_id" => 'Choose the wine lot this work is for.']);
            }

            if (in_array($type, [CellarActivityType::Transfer, CellarActivityType::Topping], true) && $vesselId === null) {
                throw ValidationException::withMessages(["{$path}.vessel_id" => 'Choose the vessel this work is for.']);
            }

            if ($lotId !== null) {
                WineLot::query()->whereKey($lotId)->firstOrFail();
            }

            if ($lotId !== null && $vesselId !== null
                && ! VesselLot::query()->where('wine_lot_id', $lotId)->where('vessel_id', $vesselId)->exists()) {
                throw ValidationException::withMessages(["{$path}.vessel_id" => 'That vessel does not hold this lot.']);
            }

            $prepared[] = [
                'type' => $type,
                'planned_for' => (string) $raw['planned_for'],
                'wine_lot_id' => $type === CellarActivityType::Vineyard ? null : $lotId,
                'vessel_id' => $type === CellarActivityType::Vineyard ? null : $vesselId,
                'note' => isset($raw['note']) && $raw['note'] !== '' ? (string) $raw['note'] : null,
                'sort_order' => isset($raw['sort_order']) ? (int) $raw['sort_order'] : null,
                'payload' => $payload,
            ];
        }

        return $prepared;
    }
}
