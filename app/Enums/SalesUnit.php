<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an inventory item is sold — the unit an order line must use for it.
 * Mirrors the order line `unit_type`. Strict: an item sold in bottles can only
 * be ordered in bottles; an item sold in cases only in cases.
 */
enum SalesUnit: string
{
    case Bottles = 'bottles';
    case Cases = 'cases';
}
