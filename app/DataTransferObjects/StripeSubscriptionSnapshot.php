<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Illuminate\Support\Carbon;

/**
 * A normalised view of a Stripe subscription, extracted from a webhook event by
 * the StripeGateway so the rest of the app (the sync action, the access service)
 * never depends on the \Stripe\* SDK types.
 */
final class StripeSubscriptionSnapshot
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $customerId,
        public readonly string $status,
        public readonly ?string $priceId,
        public readonly ?Carbon $trialEndsAt,
        public readonly ?Carbon $currentPeriodEnd,
        public readonly ?Carbon $canceledAt,
        public readonly ?Carbon $endsAt,
    ) {}
}
