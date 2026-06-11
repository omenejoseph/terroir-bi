<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DataTransferObjects\StripeSubscriptionSnapshot;
use App\Models\Tenant;
use App\Services\Billing\StripeGateway;
use RuntimeException;
use Stripe\Event;

/**
 * Typed test double for StripeGateway (no Stripe SDK calls). Bound via
 * `$this->app->instance(StripeGateway::class, $fake)`, matching the repo's
 * FakeObjectStore pattern so PHPStan stays happy.
 */
class FakeStripeGateway extends StripeGateway
{
    public string $customerId = 'cus_fake';

    public string $checkoutUrl = 'https://checkout.test/session';

    public bool $throwOnConstruct = false;

    public ?StripeSubscriptionSnapshot $snapshot = null;

    public ?string $canceledSubscriptionId = null;

    public function __construct()
    {
        // No StripeClientFactory needed for the fake.
    }

    public function createCustomer(Tenant $tenant): string
    {
        return $this->customerId;
    }

    public function createCheckoutSession(string $customerId, string $priceId, int $trialDays, array $metadata = []): string
    {
        return $this->checkoutUrl;
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->canceledSubscriptionId = $subscriptionId;
    }

    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        if ($this->throwOnConstruct) {
            throw new RuntimeException('Invalid signature');
        }

        return Event::constructFrom(['id' => 'evt_fake', 'type' => 'customer.subscription.updated']);
    }

    public function snapshotFromEvent(Event $event): ?StripeSubscriptionSnapshot
    {
        return $this->snapshot;
    }
}
