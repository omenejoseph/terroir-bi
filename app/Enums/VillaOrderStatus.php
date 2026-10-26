<?php

declare(strict_types=1);

namespace App\Enums;

/** A guest's in-villa order. Whether it is paid is separate (`paid_at`). */
enum VillaOrderStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Delivered = 'DELIVERED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }
}
