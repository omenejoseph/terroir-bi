<?php

declare(strict_types=1);

namespace App\Services\Cellar;

/**
 * Pure SO₂ dosing maths. Given a target and current free-SO₂ level, the wine
 * volume and a product's uplift coefficient (mg/L of free SO₂ per 1 unit per
 * 1 L), returns the dose to add in the product's native unit.
 *
 *     dose = (target − current) × volumeL / upliftPerUnit
 */
class So2Calculator
{
    /**
     * @return float dose in the product's unit (0 when no addition is needed)
     */
    public function dose(
        float $targetMgL,
        float $currentMgL,
        float $volumeLiters,
        float $upliftPerUnit,
    ): float {
        $delta = $targetMgL - $currentMgL;
        if ($delta <= 0 || $upliftPerUnit <= 0 || $volumeLiters <= 0) {
            return 0.0;
        }

        return ($delta * $volumeLiters) / $upliftPerUnit;
    }
}
