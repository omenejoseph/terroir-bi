<?php

declare(strict_types=1);

namespace App\Enums;

/** Lifecycle of a production plan. */
enum ProductionPlanStatus: string
{
    case Draft = 'DRAFT';
    case Confirmed = 'CONFIRMED';
    case Cancelled = 'CANCELLED';
}
