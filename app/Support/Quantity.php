<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Fixed-scale decimal arithmetic for stock quantities (scale 3), done in integer
 * units to avoid float drift. (bcmath is not assumed to be installed.)
 */
final class Quantity
{
    public const SCALE = 3;

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::format(self::toUnits($a, $scale) + self::toUnits($b, $scale), $scale);
    }

    public static function isNegative(string $value, int $scale = self::SCALE): bool
    {
        return self::toUnits($value, $scale) < 0;
    }

    private static function toUnits(string $value, int $scale): int
    {
        return (int) round((float) $value * (10 ** $scale));
    }

    private static function format(int $units, int $scale): string
    {
        $multiplier = 10 ** $scale;
        $sign = $units < 0 ? '-' : '';
        $abs = abs($units);
        $whole = intdiv($abs, $multiplier);
        $fraction = str_pad((string) ($abs % $multiplier), $scale, '0', STR_PAD_LEFT);

        return $sign.$whole.'.'.$fraction;
    }
}
