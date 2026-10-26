<?php

declare(strict_types=1);

namespace App\Enums;

enum VillaBookingStatus: string
{
    case Upcoming = 'UPCOMING';
    case CheckedIn = 'CHECKED_IN';
    case CheckedOut = 'CHECKED_OUT';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'Upcoming',
            self::CheckedIn => 'Checked in',
            self::CheckedOut => 'Checked out',
            self::Cancelled => 'Cancelled',
        };
    }
}
