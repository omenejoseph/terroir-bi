<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

use App\Support\Money\Currency;
use RuntimeException;

class CurrencyMismatchException extends RuntimeException
{
    public static function between(Currency $a, Currency $b): self
    {
        return new self("Cannot operate on Money of differing currencies [{$a->code}] and [{$b->code}]. Conversion is not yet supported.");
    }
}
