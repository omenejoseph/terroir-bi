<?php

declare(strict_types=1);

namespace App\Enums;

/** What a protocol additive's dose is measured against. */
enum DoseBasis: string
{
    case PerLiter = 'per_L';
    case PerHectoliter = 'per_hL';
    case PerKg = 'per_kg';
    case Per100Kg = 'per_100kg';

    /** Whether the dose scales with grape weight rather than wine volume. */
    public function needsGrapeWeight(): bool
    {
        return $this === self::PerKg || $this === self::Per100Kg;
    }
}
