<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

use InvalidArgumentException;

class UnsupportedCurrencyException extends InvalidArgumentException
{
    public static function for(string $code): self
    {
        return new self("Unsupported currency [{$code}]. Add it to config/money.php.");
    }
}
