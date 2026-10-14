<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Enums\CellarActivityType;
use App\Models\CellarActivityRound;
use App\Models\EnologicalProduct;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\CellarClock;
use App\Services\Cellar\So2DosePlanner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Plan for cellar team" on the SO₂ screen: the same server-side doses as
 * "Apply now", turned into one planned ADDITION per vessel (or lot), grouped
 * in a round with a board card. Nothing is applied until the team ticks each
 * item on Cellar › Today.
 */
class CreateSo2RoundAction
{
    public function __construct(
        private readonly So2DosePlanner $planner,
        private readonly CreateCellarActivitiesAction $create,
        private readonly CellarClock $clock,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{enological_product_id: string, mode?: string|null, note?: string|null, name?: string|null, planned_for: string, assigned_to_id?: string|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, mg_l: float|int|string}>}  $data
     * @return array{round_id: string|null, work_order_id: string|null, count: int}
     */
    public function execute(array $data, string $userId): array
    {
        return DB::transaction(function () use ($data, $userId): array {
            $plan = $this->planner->plan($data);

            if ($plan['lines'] === []) {
                throw ValidationException::withMessages(['items' => 'None of the selected wines needs SO₂ at these levels.']);
            }

            /** @var EnologicalProduct $product */
            $product = EnologicalProduct::query()->whereKey($data['enological_product_id'])->firstOrFail();

            $activities = [];
            foreach ($plan['lines'] as $i => $line) {
                $activities[] = [
                    'type' => CellarActivityType::Addition->value,
                    'planned_for' => $data['planned_for'],
                    'wine_lot_id' => $line['wine_lot_id'],
                    'vessel_id' => $line['vessel_id'],
                    'sort_order' => $i,
                    'note' => isset($data['note']) && $data['note'] !== '' ? $data['note'] : null,
                    'payload' => [
                        'enological_product_id' => $product->getKey(),
                        'product_name' => $product->name,
                        'category' => 'SO2',
                        'unit' => $product->unit,
                        'quantity' => (float) $line['quantity'],
                        'cost_per_unit' => $product->cost_per_unit?->getMinorAmount(),
                        'target_note' => $line['note'],
                    ],
                ];
            }

            $name = trim((string) ($data['name'] ?? ''));

            $result = $this->create->execute([
                'round_name' => $name !== '' ? $name : 'SO₂ '.$this->clock->now()->format('d.m.Y'),
                'assigned_to_id' => $data['assigned_to_id'] ?? null,
                'activities' => $activities,
            ], $userId);

            $round = CellarActivityRound::query()->whereKey((string) $result['round_id'])->firstOrFail();
            $this->audit->record(Auth::user(), 'cellar.so2_round_planned', $round, [
                'enological_product_id' => $product->getKey(),
                'lines' => count($plan['lines']),
                'total' => $plan['totals']['quantity'],
                'unit' => $product->unit,
            ]);

            return $result;
        });
    }
}
