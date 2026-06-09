<?php

declare(strict_types=1);

namespace App\Enums;

enum InflowStatus: string
{
    case Pending = 'PENDING';   // invoiced / expected, not yet received
    case Received = 'RECEIVED'; // money in the bank

    public function isReceived(): bool
    {
        return $this === self::Received;
    }
}
