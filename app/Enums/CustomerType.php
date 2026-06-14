<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The sales channel a customer represents. Drives the dashboard's revenue-by-
 * channel breakdown. Hospitality/Accommodation will arrive with their own
 * module later; for now on-premise buyers fall under Wholesale.
 */
enum CustomerType: string
{
    case Wholesale = 'WHOLESALE';
    case Retail = 'RETAIL';
    case Agency = 'AGENCY';
    case Shipshop = 'SHIPSHOP';
    case Other = 'OTHER';

    /** Frontend channel key used in the dashboard payload. */
    public function channelKey(): string
    {
        return match ($this) {
            self::Wholesale => 'wholesale',
            self::Retail => 'retail',
            self::Agency => 'agency',
            self::Shipshop => 'shipshop',
            self::Other => 'other',
        };
    }
}
