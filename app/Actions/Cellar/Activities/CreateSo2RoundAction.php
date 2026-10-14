<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Enums\CellarActivityType;

/**
 * "Plan for cellar team" on the SO₂ screen: one ADDITION per vessel (or lot)
 * with the computed dose, grouped into a round with a board card.
 */
class CreateSo2RoundAction
{
    public function __construct(private readonly CreateCellarActivitiesAction $create) {}

    /**
     * @param  array{name?: string|null, planned_for: string, assigned_to_id?: string|null, enological_product_id?: string|null, product_name: string, unit: string, cost_per_unit?: int|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, quantity: float|int|string, target_note?: string|null}>}  $data
     * @return array{round_id: string|null, work_order_id: string|null, count: int}
     */
    public function execute(array $data, string $userId): array
    {
        $activities = [];
        foreach ($data['items'] as $i => $item) {
            $activities[] = [
                'type' => CellarActivityType::Addition->value,
                'planned_for' => $data['planned_for'],
                'wine_lot_id' => $item['wine_lot_id'],
                'vessel_id' => $item['vessel_id'] ?? null,
                'sort_order' => $i,
                'payload' => [
                    'enological_product_id' => $data['enological_product_id'] ?? null,
                    'product_name' => $data['product_name'],
                    'category' => 'SO2',
                    'unit' => $data['unit'],
                    'quantity' => round((float) $item['quantity'], 2),
                    'cost_per_unit' => $data['cost_per_unit'] ?? null,
                    'target_note' => $item['target_note'] ?? null,
                ],
            ];
        }

        $name = trim((string) ($data['name'] ?? ''));

        return $this->create->execute([
            'round_name' => $name !== '' ? $name : 'SO₂ round',
            'assigned_to_id' => $data['assigned_to_id'] ?? null,
            'activities' => $activities,
        ], $userId);
    }
}
