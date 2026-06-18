<?php

declare(strict_types=1);

namespace App\Enums;

/** Status of a grape supply contract. */
enum GrapeContractStatus: string
{
    case Active = 'ACTIVE';
    case Fulfilled = 'FULFILLED';
    case Cancelled = 'CANCELLED';
}
