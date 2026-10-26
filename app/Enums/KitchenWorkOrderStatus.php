<?php

declare(strict_types=1);

namespace App\Enums;

/** A kitchen prep ticket's state. Prep tickets never touch stock. */
enum KitchenWorkOrderStatus: string
{
    case Planned = 'PLANNED';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
