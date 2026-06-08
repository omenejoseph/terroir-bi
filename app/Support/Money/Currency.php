<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * A lightweight currency descriptor. minorUnit drives how the Money value object
 * converts between major and minor units, so non-2-decimal currencies can be
 * supported later without changing call sites.
 */
final class Currency
{
    public function __construct(
        public readonly string $code,
        public readonly int $minorUnit,
        public readonly string $symbol,
        public readonly string $name,
    ) {}

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
