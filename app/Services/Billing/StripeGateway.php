<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\StripeSubscriptionSnapshot;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use RuntimeException;
use Stripe\Event;
use Stripe\Webhook;

/**
 * The single boundary to the Stripe SDK. Everything else (actions, the webhook
 * controller, the access service) speaks our own types — Stripe objects never
 * leak past this class. Mock THIS in tests; the SDK is never exercised there.
 */
class StripeGateway
{
    public function __construct(private readonly StripeClientFactory $factory) {}

    /** Create a Stripe customer for the tenant; returns the customer id. */
    public function createCustomer(Tenant $tenant): string
    {
        $customer = $this->factory->make()->customers->create([
            'name' => $tenant->name,
            'metadata' => ['tenant_id' => $tenant->getKey()],
        ]);

        return (string) $customer->id;
    }

    /**
     * Create a hosted Checkout session in subscription mode (collects a card and
     * sets up auto-debit, with an optional trial); returns the URL to email out.
     *
     * @param  array<string, string>  $metadata
     */
    public function createCheckoutSession(string $customerId, string $priceId, int $trialDays, array $metadata = []): string
    {
        $params = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'payment_method_collection' => 'always',
            'success_url' => (string) config('services.stripe.success_url'),
            'cancel_url' => (string) config('services.stripe.cancel_url'),
            'metadata' => $metadata,
        ];

        if ($trialDays > 0) {
            $params['subscription_data'] = ['trial_period_days' => $trialDays];
        }

        $session = $this->factory->make()->checkout->sessions->create($params);

        $url = $session->url;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Stripe did not return a checkout URL.');
        }

        return $url;
    }

    /** Cancel a subscription at period end (gives the read-only window its notice). */
    public function cancelSubscription(string $subscriptionId): void
    {
        $this->factory->make()->subscriptions->update($subscriptionId, ['cancel_at_period_end' => true]);
    }

    /** Verify a webhook payload's signature and return the event. */
    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        return Webhook::constructEvent($payload, $signature, $secret);
    }

    /**
     * Normalise the subscription carried by a relevant event, or null if the
     * event is not subscription-related.
     */
    public function snapshotFromEvent(Event $event): ?StripeSubscriptionSnapshot
    {
        $arr = $event->toArray();
        $type = $this->str($arr['type'] ?? null);
        $object = $arr['data']['object'] ?? null;

        if (! is_array($object)) {
            return null;
        }

        if (in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
            return $this->snapshotFromSubscription($object);
        }

        if ($type === 'checkout.session.completed') {
            $subscriptionId = $this->str($object['subscription'] ?? null);

            if ($subscriptionId === null) {
                return null;
            }

            return $this->snapshotFromSubscription(
                $this->factory->make()->subscriptions->retrieve($subscriptionId)->toArray(),
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function snapshotFromSubscription(array $s): ?StripeSubscriptionSnapshot
    {
        $id = $this->str($s['id'] ?? null);
        $customer = $this->str($s['customer'] ?? null);
        $status = $this->str($s['status'] ?? null);

        if ($id === null || $customer === null || $status === null) {
            return null;
        }

        $price = null;
        $items = $s['items'] ?? null;
        if (is_array($items) && is_array($items['data'] ?? null) && is_array($items['data'][0] ?? null)) {
            $priceObj = $items['data'][0]['price'] ?? null;
            $price = is_array($priceObj) ? $this->str($priceObj['id'] ?? null) : null;
        }

        return new StripeSubscriptionSnapshot(
            subscriptionId: $id,
            customerId: $customer,
            status: $status,
            priceId: $price,
            trialEndsAt: $this->ts($s['trial_end'] ?? null),
            currentPeriodEnd: $this->ts($s['current_period_end'] ?? null),
            canceledAt: $this->ts($s['canceled_at'] ?? null),
            endsAt: $this->ts($s['cancel_at'] ?? null),
        );
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function ts(mixed $value): ?Carbon
    {
        return is_int($value) && $value > 0 ? Carbon::createFromTimestamp($value) : null;
    }
}
