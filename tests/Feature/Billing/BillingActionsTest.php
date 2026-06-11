<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Billing\CreateBillingCheckoutLinkAction;
use App\Actions\Billing\SendBillingSetupLinkAction;
use App\Actions\Billing\SyncSubscriptionFromStripeAction;
use App\DataTransferObjects\StripeSubscriptionSnapshot;
use App\Enums\Module;
use App\Enums\TenantRole;
use App\Models\Plan;
use App\Models\TenantSubscription;
use App\Notifications\BillingSetupLinkNotification;
use App\Services\Billing\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

class BillingActionsTest extends TestCase
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

    private function paidPlan(): Plan
    {
        return Plan::create([
            'name' => 'Paid', 'slug' => 'paid-a', 'currency' => 'EUR',
            'modules' => Module::values(), 'stripe_price_id' => 'price_x', 'trial_days' => 14,
        ]);
    }

    public function test_sync_upserts_the_matching_subscription_row(): void
    {
        $tenant = $this->createTenant();
        $sub = TenantSubscription::create(['tenant_id' => $tenant->getKey(), 'stripe_customer_id' => 'cus_1']);

        app(SyncSubscriptionFromStripeAction::class)->execute(new StripeSubscriptionSnapshot(
            subscriptionId: 'sub_1',
            customerId: 'cus_1',
            status: 'active',
            priceId: 'price_x',
            trialEndsAt: null,
            currentPeriodEnd: Carbon::parse('2026-07-01'),
            canceledAt: null,
            endsAt: null,
        ));

        $sub->refresh();
        $this->assertSame('sub_1', $sub->stripe_subscription_id);
        $this->assertSame('active', $sub->stripe_status);
        $this->assertSame('2026-07-01', $sub->current_period_end?->toDateString());
    }

    public function test_sync_ignores_unknown_subscriptions(): void
    {
        $result = app(SyncSubscriptionFromStripeAction::class)->execute(new StripeSubscriptionSnapshot(
            'sub_x', 'cus_unknown', 'active', null, null, null, null, null,
        ));

        $this->assertNull($result);
        $this->assertSame(0, TenantSubscription::query()->count());
    }

    public function test_create_checkout_link_provisions_customer_and_returns_url(): void
    {
        $tenant = $this->createTenant(['plan_id' => $this->paidPlan()->getKey()]);
        $this->stripe->customerId = 'cus_x';
        $this->stripe->checkoutUrl = 'https://checkout.test/abc';

        $url = app(CreateBillingCheckoutLinkAction::class)->execute($tenant);

        $this->assertSame('https://checkout.test/abc', $url);
        $this->assertSame('cus_x', $tenant->subscription()->first()?->stripe_customer_id);
    }

    public function test_send_billing_setup_link_emails_the_url(): void
    {
        Notification::fake();
        $tenant = $this->createTenant(['plan_id' => $this->paidPlan()->getKey()]);
        $this->createMember($tenant, [TenantRole::Admin], ['email' => 'owner@winery.test']);
        $this->stripe->checkoutUrl = 'https://checkout.test/abc';

        $url = app(SendBillingSetupLinkAction::class)->execute($tenant);

        $this->assertSame('https://checkout.test/abc', $url);
        Notification::assertSentOnDemand(BillingSetupLinkNotification::class);
    }
}
