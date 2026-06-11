<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of the app a tenant may use right now, derived from its subscription
 * state and the plan's grace windows by App\Services\Billing\SubscriptionAccessService.
 */
enum AccessLevel: string
{
    /** Normal access (active, trialing, or within the full-access grace window). */
    case Full = 'full';

    /** Read-only grace window: GET allowed, writes blocked. */
    case ReadOnly = 'read_only';

    /** Past all grace (or suspended/canceled): no access. */
    case Blocked = 'blocked';

    public function allowsWrites(): bool
    {
        return $this === self::Full;
    }

    public function allowsReads(): bool
    {
        return $this !== self::Blocked;
    }
}
