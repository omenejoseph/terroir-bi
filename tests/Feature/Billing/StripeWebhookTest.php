<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\DataTransferObjects\StripeSubscriptionSnapshot;
use App\Models\TenantSubscription;
use App\Services\Billing\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    public function test_webhook_syncs_the_subscription(): void
    {
        $tenant = $this->createTenant();
        $sub = TenantSubscription::create(['tenant_id' => $tenant->getKey(), 'stripe_customer_id' => 'cus_1']);

        $this->stripe->snapshot = new StripeSubscriptionSnapshot(
            subscriptionId: 'sub_1',
            customerId: 'cus_1',
            status: 'active',
            priceId: 'price_x',
            trialEndsAt: null,
            currentPeriodEnd: Carbon::parse('2026-08-01'),
            canceledAt: null,
            endsAt: null,
        );

        $this->postJson('/api/v1/stripe/webhook', ['mock' => true], ['Stripe-Signature' => 'sig'])
            ->assertOk();

        $sub->refresh();
        $this->assertSame('sub_1', $sub->stripe_subscription_id);
        $this->assertSame('active', $sub->stripe_status);
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        $this->stripe->throwOnConstruct = true;

        $this->postJson('/api/v1/stripe/webhook', ['mock' => true], ['Stripe-Signature' => 'bad'])
            ->assertStatus(400);
    }
}
