<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Billing\CreateStripePriceForPlanAction;
use App\Enums\Module;
use App\Models\Plan;
use App\Services\Billing\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

class CreateStripePriceForPlanActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Growth',
            'slug' => 'growth',
            'currency' => 'EUR',
            'price_minor' => 2900,
            'interval' => 'month',
            'modules' => Module::values(),
            'trial_days' => 14,
        ], $overrides));
    }

    public function test_it_pushes_the_price_to_stripe_and_stores_the_id(): void
    {
        $this->stripe->priceId = 'price_new';
        $plan = $this->plan();

        $result = app(CreateStripePriceForPlanAction::class)->execute($plan);

        $this->assertSame('price_new', $result->stripe_price_id);
        $this->assertSame('price_new', $plan->fresh()?->stripe_price_id);
        $this->assertSame($plan->getKey(), $this->stripe->createdPriceForPlan);
    }

    public function test_it_refuses_to_overwrite_an_existing_stripe_price(): void
    {
        $plan = $this->plan(['stripe_price_id' => 'price_old']);

        $this->expectException(RuntimeException::class);

        try {
            app(CreateStripePriceForPlanAction::class)->execute($plan);
        } finally {
            // The Stripe gateway must not have been called.
            $this->assertNull($this->stripe->createdPriceForPlan);
            $this->assertSame('price_old', $plan->fresh()?->stripe_price_id);
        }
    }
}
