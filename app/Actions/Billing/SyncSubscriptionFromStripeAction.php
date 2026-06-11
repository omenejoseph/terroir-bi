<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\DataTransferObjects\StripeSubscriptionSnapshot;
use App\Models\TenantSubscription;

/**
 * Idempotently writes a Stripe subscription snapshot onto the matching tenant's
 * billing row (matched by subscription id, falling back to customer id, which is
 * set when the checkout link is generated). The access decision derives entirely
 * from these fields, so no TenantStatus juggling here — that stays an admin
 * override.
 */
class SyncSubscriptionFromStripeAction
{
    public function execute(StripeSubscriptionSnapshot $snapshot): ?TenantSubscription
    {
        $subscription = TenantSubscription::query()
            ->where('stripe_subscription_id', $snapshot->subscriptionId)
            ->orWhere('stripe_customer_id', $snapshot->customerId)
            ->first();

        // Unknown subscription (no tenant ever generated a link for it) → ignore.
        if ($subscription === null) {
            return null;
        }

        $subscription->fill([
            'stripe_subscription_id' => $snapshot->subscriptionId,
            'stripe_customer_id' => $snapshot->customerId,
            'stripe_status' => $snapshot->status,
            'stripe_price_id' => $snapshot->priceId,
            'trial_ends_at' => $snapshot->trialEndsAt,
            'current_period_end' => $snapshot->currentPeriodEnd,
            'canceled_at' => $snapshot->canceledAt,
            'ends_at' => $snapshot->endsAt,
        ])->save();

        return $subscription;
    }
}
