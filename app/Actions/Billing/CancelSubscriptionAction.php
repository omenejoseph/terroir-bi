<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Tenant;
use App\Services\Billing\StripeGateway;
use RuntimeException;

/**
 * Cancels the tenant's Stripe subscription at period end. The resulting Stripe
 * webhook syncs the row; the read-only/blocked transition then follows from the
 * access state machine.
 */
class CancelSubscriptionAction
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function execute(Tenant $tenant): void
    {
        $tenant->loadMissing('subscription');
        $subscriptionId = $tenant->subscription?->stripe_subscription_id;

        if ($subscriptionId === null) {
            throw new RuntimeException('The tenant has no active Stripe subscription to cancel.');
        }

        $this->stripe->cancelSubscription($subscriptionId);
    }
}
