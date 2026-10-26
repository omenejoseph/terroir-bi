<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a support ticket stands. "Waiting on customer" is set by a staff
 * reply and cleared by the customer's next one, so the queue shows who owes
 * the next move.
 */
enum SupportTicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingOnCustomer = 'waiting_on_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** Open, in progress or waiting — still on someone's list. */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
