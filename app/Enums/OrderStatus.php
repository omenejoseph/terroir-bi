<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Received = 'RECEIVED';
    case InProcess = 'IN_PROCESS';
    case ReadyToShip = 'READY_TO_SHIP';
    case Shipped = 'SHIPPED';

    public function isShipped(): bool
    {
        return $this === self::Shipped;
    }
}
