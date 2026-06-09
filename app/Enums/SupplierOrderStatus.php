<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierOrderStatus: string
{
    case Draft = 'DRAFT';
    case Sent = 'SENT';
    case Confirmed = 'CONFIRMED';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';
}
