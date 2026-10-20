<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Units of measure that convert into one another, so a recipe can say "75 cl of this wine" or
 * "5 ml of that" while the cost is held per litre. Only volume (ml, cl, l) and mass (g, kg) convert;
 * every other unit (bottles, cases, units, …) stands on its own and is never converted.
 */
final class Measure
{
    /** unit → [family, factor to the family's base unit (litre / kilogram)] */
    private const UNITS = [
        'ml' => ['volume', 0.001],
        'cl' => ['volume', 0.01],
        'l' => ['volume', 1.0],
        'g' => ['mass', 0.001],
        'kg' => ['mass', 1.0],
    ];

    private const ALIASES = [
        'liter' => 'l', 'liters' => 'l', 'litre' => 'l', 'litres' => 'l', 'lt' => 'l',
        'milliliter' => 'ml', 'milliliters' => 'ml', 'millilitre' => 'ml', 'millilitres' => 'ml',
        'centiliter' => 'cl', 'centilitre' => 'cl',
        'gram' => 'g', 'grams' => 'g', 'kilogram' => 'kg', 'kilograms' => 'kg', 'kgs' => 'kg',
    ];

    /** The canonical code for a unit ("Liters" → "l"), or null when it is not a convertible one. */
    public static function normalize(?string $unit): ?string
    {
        $unit = strtolower(trim((string) $unit));
        $unit = self::ALIASES[$unit] ?? $unit;

        return isset(self::UNITS[$unit]) ? $unit : null;
    }

    public static function family(?string $unit): ?string
    {
        $unit = self::normalize($unit);

        return $unit === null ? null : self::UNITS[$unit][0];
    }

    /**
     * The units a quantity of something held in `$baseUnit` may be entered in — empty when the unit
     * does not convert (bottles, cases, …).
     *
     * @return list<string>
     */
    public static function options(?string $baseUnit): array
    {
        $family = self::family($baseUnit);

        return $family === null
            ? []
            : array_keys(array_filter(self::UNITS, fn (array $u): bool => $u[0] === $family));
    }

    /** How many `$to` make one `$from` (1 ml = 0.001 l), or null when they do not convert. */
    public static function factor(?string $from, ?string $to): ?float
    {
        $from = self::normalize($from);
        $to = self::normalize($to);

        if ($from === null || $to === null || self::UNITS[$from][0] !== self::UNITS[$to][0]) {
            return null;
        }

        return self::UNITS[$from][1] / self::UNITS[$to][1];
    }
}
