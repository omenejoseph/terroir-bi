<?php

declare(strict_types=1);

namespace App\Enums;

enum CostStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Paid = 'PAID';
}
