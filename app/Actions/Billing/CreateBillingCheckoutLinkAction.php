<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Billing\StripeGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ensures the tenant has a Stripe customer + billing row, then creates a hosted
 * Checkout session (card + auto-debit, with the plan's trial) and returns its
 * URL. Reusable by the back office and a future tenant self-service endpoint.
 */
class CreateBillingCheckoutLinkAction
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function execute(Tenant $tenant): string
    {
        $tenant->loadMissing(['plan', 'subscription']);
        $plan = $tenant->plan;

        if ($plan === null || $plan->stripe_price_id === null) {
            throw new RuntimeException('The tenant has no billable plan (missing Stripe price).');
        }

        return DB::transaction(function () use ($tenant, $plan): string {
            $subscription = $tenant->subscription ?? new TenantSubscription(['tenant_id' => $tenant->getKey()]);

            if ($subscription->stripe_customer_id === null) {
                $subscription->stripe_customer_id = $this->stripe->createCustomer($tenant);
            }
            $subscription->stripe_price_id = $plan->stripe_price_id;
            $subscription->save();

            return $this->stripe->createCheckoutSession(
                (string) $subscription->stripe_customer_id,
                (string) $plan->stripe_price_id,
                $plan->trial_days,
                ['tenant_id' => $tenant->getKey()],
            );
        });
    }
}
