<?php

declare(strict_types=1);

namespace App\Enums;

/** Set by platform admins when triaging; every ticket starts at normal. */
enum SupportTicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p): string => $p->value, self::cases());
    }
}
