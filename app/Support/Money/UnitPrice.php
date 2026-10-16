<?php

declare(strict_types=1);

namespace App\Support\Money;

use JsonSerializable;
use Stringable;

/**
 * A per-unit price that can be smaller than one minor unit — what an enological
 * product costs per gram or millilitre (potassium metabisulphite: 0.006 €/g).
 *
 * Kept in minor units, like Money, but with four decimals of a minor unit
 * (0.6000 cents). Whole-cent storage turned 0.006 €/g into 0.01 €/g and
 * 0.003 €/mL into nothing; totals (quantity × unit price) are rounded to whole
 * minor units only when they become Money.
 */
final class UnitPrice implements JsonSerializable, Stringable
{
    /** Decimals of a minor unit kept. */
    public const SCALE = 4;

    private function __construct(
        /** Minor units as a decimal string with SCALE decimals, e.g. "0.6000". */
        public readonly string $minor,
        public readonly Currency $currency,
    ) {}

    public static function fromMinor(string|int|float $minor, string|Currency $currency): self
    {
        return new self(self::normalize($minor), $currency instanceof Currency ? $currency : CurrencyRegistry::get($currency));
    }

    /** Minor units rounded to SCALE decimals, as a string ("0.6000"). */
    public static function normalize(string|int|float $minor): string
    {
        return number_format(round((float) $minor, self::SCALE), self::SCALE, '.', '');
    }

    public function minorFloat(): float
    {
        return (float) $this->minor;
    }

    /** The price rounded to whole minor units — for documents that only take whole cents. */
    public function roundedMinor(): int
    {
        return (int) round($this->minorFloat());
    }

    /** quantity × price, rounded half away from zero to whole minor units. */
    public function totalFor(string|int|float $quantity): int
    {
        return (int) round($this->minorFloat() * (float) $quantity);
    }

    /** The price in major units, trimmed to what it needs but never fewer than the currency's decimals ("0.006", "1.10"). */
    public function toMajor(): string
    {
        $unit = $this->currency->minorUnit;
        $major = number_format($this->minorFloat() / (10 ** $unit), $unit + self::SCALE, '.', '');
        if ($unit === 0 && ! str_contains($major, '.')) {
            return $major;
        }
        [$whole, $fraction] = explode('.', $major);
        $fraction = rtrim($fraction, '0');

        return $whole.'.'.str_pad($fraction, $unit, '0');
    }

    /** @return array{minor:float, currency:string, formatted:string} */
    public function jsonSerialize(): array
    {
        return [
            'minor' => $this->minorFloat(),
            'currency' => $this->currency->code,
            'formatted' => $this->toMajor(),
        ];
    }

    public function __toString(): string
    {
        return $this->toMajor().' '.$this->currency->code;
    }
}
