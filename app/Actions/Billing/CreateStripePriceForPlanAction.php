<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Services\Billing\StripeGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pushes a plan's price to Stripe (product + recurring price) and stores the new
 * price id on the plan, so the back office can "set the price" without leaving the
 * app. Refuses to overwrite an existing Stripe price — that would orphan live
 * subscriptions billing on the old one.
 */
class CreateStripePriceForPlanAction
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function execute(Plan $plan): Plan
    {
        if ($plan->stripe_price_id !== null) {
            throw new RuntimeException('This plan already has a Stripe price; create a new plan to change the amount.');
        }

        return DB::transaction(function () use ($plan): Plan {
            $priceId = $this->stripe->createPrice($plan);

            $plan->update(['stripe_price_id' => $priceId]);

            return $plan;
        });
    }
}
