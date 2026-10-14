<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Enums\DoseBasis;

/**
 * How much of a protocol additive a volume of wine needs: dose × basis —
 * per_L / per_hL on the litres, per_kg / per_100kg on the grape weight behind
 * those litres. Null when the basis needs grape weight and there is none.
 * Shared by "Plan protocol additions" and the protocol needs report.
 */
final class ProtocolDose
{
    public static function quantity(DoseBasis $basis, float $dose, float $liters, ?float $grapeKg): ?float
    {
        $factor = match ($basis) {
            DoseBasis::PerLiter => $liters,
            DoseBasis::PerHectoliter => $liters / 100,
            DoseBasis::PerKg => $grapeKg !== null && $grapeKg > 0 ? $grapeKg : null,
            DoseBasis::Per100Kg => $grapeKg !== null && $grapeKg > 0 ? $grapeKg / 100 : null,
        };

        return $factor === null ? null : round($dose * $factor, 2);
    }
}
