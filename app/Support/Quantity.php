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

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::format(self::toUnits($a, $scale) - self::toUnits($b, $scale), $scale);
    }

    /** Re-express any numeric string at the fixed scale (e.g. "10" → "10.000"). */
    public static function normalize(string $value, int $scale = self::SCALE): string
    {
        return self::format(self::toUnits($value, $scale), $scale);
    }

    /** Flip the sign of a quantity ("3.500" → "-3.500"). */
    public static function negate(string $value, int $scale = self::SCALE): string
    {
        return self::format(-self::toUnits($value, $scale), $scale);
    }

    /** -1 / 0 / 1 — like the spaceship operator, at fixed scale. */
    public static function compare(string $a, string $b, int $scale = self::SCALE): int
    {
        return self::toUnits($a, $scale) <=> self::toUnits($b, $scale);
    }

    /** Multiply a quantity by a whole number (e.g. cases → bottles). */
    public static function mulInt(string $value, int $factor, int $scale = self::SCALE): string
    {
        return self::format(self::toUnits($value, $scale) * $factor, $scale);
    }

    /** Multiply two quantities (e.g. recipe-line qty × produced output qty). */
    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        $product = self::toUnits($a, $scale) * self::toUnits($b, $scale);

        return self::format((int) round($product / (10 ** $scale)), $scale);
    }

    /** Divide a quantity by a whole number (e.g. bottles → cases), rounded to scale. */
    public static function divInt(string $value, int $divisor, int $scale = self::SCALE): string
    {
        if ($divisor === 0) {
            return self::format(0, $scale);
        }

        return self::format((int) round(self::toUnits($value, $scale) / $divisor), $scale);
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
