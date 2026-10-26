<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * IN adds, OUT takes away, ADJUSTMENT sets the stock to a counted figure. The movement always
 * stores the signed change, so the history adds up to the stock.
 */
enum KitchenMovementType: string
{
    case In = 'IN';
    case Out = 'OUT';
    case Adjustment = 'ADJUSTMENT';
}
