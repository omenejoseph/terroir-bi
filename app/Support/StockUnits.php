<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which stocking units are counted in whole pieces. You can hold 1 bottle or 2 cases, never 1.3 of
 * either; kilograms and litres are measured, so they keep their decimals.
 */
final class StockUnits
{
    private const WHOLE = ['bottle', 'bottles', 'case', 'cases', 'unit', 'units'];

    public static function isWhole(?string $unit): bool
    {
        return in_array(strtolower(trim((string) $unit)), self::WHOLE, true);
    }

    /** True when the quantity has no fractional part ("5", "5.0" and "-3.000" are whole). */
    public static function isWholeNumber(string|int|float $quantity): bool
    {
        return is_numeric($quantity) && (float) $quantity === floor((float) $quantity);
    }

    /** The validation message when a fractional quantity is given for a whole-unit item, else null. */
    public static function wholeError(?string $unit, mixed $quantity): ?string
    {
        if (is_numeric($quantity) && self::isWhole($unit) && ! self::isWholeNumber($quantity)) {
            return __('This item is counted in whole :unit — no decimals.', ['unit' => $unit]);
        }

        return null;
    }
}
