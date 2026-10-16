<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Support;

use App\Enums\CellarActivityType;
use App\Services\LegacyImport\ImportContext;

/**
 * Maps a prototype CellarActivity payload (camelCase, legacy ids, money in
 * major units) to the rebuild's shape (snake_case, mapped ids, minor units —
 * see App\Services\Cellar\ActivityPayload). Unknown keys are dropped.
 */
final class LegacyActivityPayload
{
    /** Prototype analysis metric keys → CellarAnalysis::PARAMETERS. */
    private const METRICS = [
        'freeSO2' => 'free_so2', 'totalSO2' => 'total_so2', 'pH' => 'ph', 'temperature' => 'temperature',
        'density' => 'density', 'residualSugar' => 'residual_sugar', 'totalAcidity' => 'total_acidity',
        'volatileAcidity' => 'volatile_acidity', 'alcohol' => 'alcohol',
    ];

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function map(CellarActivityType $type, array $p, ImportContext $ctx): array
    {
        $id = fn (string $table, mixed $legacy): ?string => $legacy === null || $legacy === '' ? null : $ctx->ids->get($table, (string) $legacy);

        $out = match ($type) {
            CellarActivityType::Addition => [
                'enological_product_id' => $id('EnologicalProduct', $p['productId'] ?? null),
                'product_name' => (string) ($p['productName'] ?? 'Addition'),
                'category' => $p['category'] ?? null,
                'unit' => (string) ($p['unit'] ?? 'g'),
                'quantity' => $p['quantity'] ?? null,
                'cost_per_unit' => is_numeric($c = Normalize::unitPriceOrNull($p['costPerUnit'] ?? null)) ? (float) $c : null,
                'dose_per_hl' => $p['dosePerHl'] ?? null,
                'target_note' => $p['targetNote'] ?? null,
            ],
            CellarActivityType::Analysis => [
                'metrics' => array_values(array_filter(array_map(
                    fn (mixed $m): ?string => self::METRICS[(string) $m] ?? null,
                    (array) ($p['metrics'] ?? []),
                ))),
            ],
            CellarActivityType::Transfer => [
                'from_vessel_id' => $id('Vessel', $p['fromVesselId'] ?? null),
                'to_vessel_id' => $id('Vessel', $p['toVesselId'] ?? null),
                'liters' => $p['liters'] ?? null,
                'to_lot_id' => $id('WineLot', $p['toLotId'] ?? null),
            ],
            CellarActivityType::Process => ['kind' => (string) ($p['kind'] ?? 'Process'), 'note' => $p['note'] ?? null],
            CellarActivityType::Topping => ['liters' => $p['liters'] ?? null, 'source_vessel_id' => null, 'reason' => $p['reason'] ?? null],
            CellarActivityType::Vineyard => [
                'kind' => (string) ($p['kind'] ?? 'Vineyard'),
                'parcel_id' => $id('VineyardParcel', $p['parcelId'] ?? null),
                'parcel_name' => $p['parcelName'] ?? null,
            ],
        };

        if (isset($p['applied']) && is_array($p['applied'])) {
            $applied = $p['applied'];
            if (isset($applied['values']) && is_array($applied['values'])) {
                $values = [];
                foreach ($applied['values'] as $k => $v) {
                    if (isset(self::METRICS[$k])) {
                        $values[self::METRICS[$k]] = $v;
                    }
                }
                $applied['values'] = $values;
            }
            if (isset($applied['parcelName'])) {
                $applied['parcel_name'] = $applied['parcelName'];
                unset($applied['parcelName']);
            }
            $out['applied'] = $applied;
        }

        return array_filter($out, fn (mixed $v): bool => $v !== null);
    }
}
