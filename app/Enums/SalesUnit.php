<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an inventory item is sold — the default unit of its order lines.
 * Mirrors the order line `unit_type`. Default unit for new lines; either unit may be ordered (price,
 * stock and cost scale by bottles per case).
 */
enum SalesUnit: string
{
    case Bottles = 'bottles';
    case Cases = 'cases';
}
