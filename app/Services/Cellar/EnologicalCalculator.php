<?php

declare(strict_types=1);

namespace App\Services\Cellar;

/**
 * The prototype's enological calculators (acid, general addition,
 * chaptalisation, press yield), ported 1:1. SO₂ lives in So2Calculator.
 */
class EnologicalCalculator
{
    /**
     * Tartaric acid to raise TA: (target − current) g/L × L × 0.67 (buffering).
     *
     * @return array{grams_tartaric_acid: float}
     */
    public function acidAddition(float $volumeLiters, float $currentTa, float $targetTa): array
    {
        $deficit = max(0.0, $targetTa - $currentTa);

        return ['grams_tartaric_acid' => round($deficit * $volumeLiters * 0.67, 1)];
    }

    /**
     * Grams for a mg/L dose, adjusted for product concentration (0–100 %).
     *
     * @return array{grams_needed: float}
     */
    public function generalAddition(float $volumeLiters, float $doseMgL, ?float $concentrationPercent = null): array
    {
        $concentration = ($concentrationPercent !== null && $concentrationPercent > 0 ? $concentrationPercent : 100.0) / 100;

        return ['grams_needed' => round($doseMgL * $volumeLiters / 1000 / $concentration, 1)];
    }

    /**
     * Sugar to raise Brix (1 g/L ≈ 0.06 °Brix; 1 °Brix ≈ 0.55 % alcohol).
     *
     * @return array{kg_sugar: float, potential_alcohol_increase: float}
     */
    public function chaptalization(float $volumeLiters, float $currentBrix, float $targetBrix): array
    {
        $diff = max(0.0, $targetBrix - $currentBrix);

        return [
            'kg_sugar' => round($diff / 0.06 * $volumeLiters / 1000, 2),
            'potential_alcohol_increase' => round($diff * 0.55, 1),
        ];
    }

    /**
     * @param  list<array{type: string, volume_liters: float}>  $fractions
     * @return array{total_juice_liters: float, expected_liters: float, overall_yield: float, fractions: list<array{type: string, volume_liters: float, percent: float}>}
     */
    public function pressYield(float $grapeKg, float $yieldRatio, array $fractions): array
    {
        $total = array_sum(array_map(fn (array $f): float => $f['volume_liters'], $fractions));

        return [
            'total_juice_liters' => round($total, 1),
            'expected_liters' => round($grapeKg * $yieldRatio, 1),
            'overall_yield' => $grapeKg > 0 ? round($total / $grapeKg * 100, 1) : 0.0,
            'fractions' => array_map(fn (array $f): array => [
                'type' => $f['type'],
                'volume_liters' => $f['volume_liters'],
                'percent' => $total > 0 ? round($f['volume_liters'] / $total * 100, 1) : 0.0,
            ], $fractions),
        ];
    }
}
