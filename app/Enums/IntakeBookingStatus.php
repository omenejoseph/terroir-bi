<?php

declare(strict_types=1);

namespace App\Enums;

/** Status of a scheduled grape-reception booking. */
enum IntakeBookingStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Arrived = 'ARRIVED';
    case Processed = 'PROCESSED';
    case Cancelled = 'CANCELLED';
}
