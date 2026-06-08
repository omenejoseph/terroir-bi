<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMovementType: string
{
    case ManualIn = 'MANUAL_IN';
    case ManualOut = 'MANUAL_OUT';
    case OrderDeduct = 'ORDER_DEDUCT';
    case ProductionIn = 'PRODUCTION_IN';
    case ProductionOut = 'PRODUCTION_OUT';
    case Adjustment = 'ADJUSTMENT';

    /** Whether this movement adds (vs removes) stock by convention. */
    public function isInbound(): bool
    {
        return match ($this) {
            self::ManualIn, self::ProductionIn => true,
            self::ManualOut, self::OrderDeduct, self::ProductionOut => false,
            self::Adjustment => true, // sign carried by the quantity
        };
    }
}
