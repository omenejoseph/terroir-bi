<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Money\Exceptions\CurrencyMismatchException;
use JsonSerializable;
use Stringable;

/**
 * An immutable money value: an integer amount of minor units (e.g. cents) plus
 * a currency. All arithmetic stays in integer minor units — never floats.
 *
 * There is intentionally no conversion between currencies here; cross-currency
 * arithmetic throws. When FX is added later, this VO is the single place to wrap
 * it (the public surface — fromMinor/fromMajor/getMinorAmount — stays stable, so
 * a library like brick/money could be slotted underneath without touching callers).
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public readonly int $minor,
        public readonly Currency $currency,
    ) {}

    public static function fromMinor(int $minor, string|Currency $currency): self
    {
        return new self($minor, self::currency($currency));
    }

    /** Build from a major-unit decimal string/number (e.g. "12.34"), rounding half away from zero. */
    public static function fromMajor(string|int|float $amount, string|Currency $currency): self
    {
        $currency = self::currency($currency);
        $unit = $currency->minorUnit;

        $string = self::normalize($amount);
        $negative = str_starts_with($string, '-');
        $string = ltrim($string, '+-');

        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;

        // Keep `unit` fraction digits plus one guard digit to decide rounding.
        $fraction = str_pad($fraction, $unit + 1, '0');
        $kept = $unit > 0 ? substr($fraction, 0, $unit) : '';
        $guard = (int) substr($fraction, $unit, 1);

        $minor = (int) ($whole.$kept);

        if ($guard >= 5) {
            $minor++;
        }

        return new self($negative ? -$minor : $minor, $currency);
    }

    public static function zero(string|Currency $currency): self
    {
        return new self(0, self::currency($currency));
    }

    public function getMinorAmount(): int
    {
        return $this->minor;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getCurrencyCode(): string
    {
        return $this->currency->code;
    }

    /** The amount as a major-unit decimal string (e.g. "12.34"). Never a float. */
    public function toMajor(): string
    {
        $unit = $this->currency->minorUnit;
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);

        if ($unit === 0) {
            return $sign.(string) $abs;
        }

        $divisor = 10 ** $unit;
        $whole = intdiv($abs, $divisor);
        $fraction = str_pad((string) ($abs % $divisor), $unit, '0', STR_PAD_LEFT);

        return $sign.$whole.'.'.$fraction;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency->equals($other->currency);
    }

    /** @return array{minor:int, currency:string, formatted:string} */
    public function jsonSerialize(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency->code,
            'formatted' => $this->toMajor(),
        ];
    }

    public function __toString(): string
    {
        return $this->toMajor().' '.$this->currency->code;
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }

    private static function currency(string|Currency $currency): Currency
    {
        return $currency instanceof Currency ? $currency : CurrencyRegistry::get($currency);
    }

    private static function normalize(string|int|float $amount): string
    {
        // Avoid float scientific notation; keep a plain decimal string for bc.
        if (is_float($amount)) {
            return number_format($amount, 8, '.', '');
        }

        return (string) $amount;
    }
}
