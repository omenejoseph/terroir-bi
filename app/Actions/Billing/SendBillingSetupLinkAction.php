<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Tenant;
use App\Notifications\BillingSetupLinkNotification;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Generates a Stripe Checkout link for the tenant and emails it to the customer.
 * The single operation the back office triggers; returns the URL so the admin
 * can also copy it. Logic lives here, not in Filament.
 */
class SendBillingSetupLinkAction
{
    public function __construct(private readonly CreateBillingCheckoutLinkAction $createLink) {}

    public function execute(Tenant $tenant, ?string $email = null): string
    {
        $url = $this->createLink->execute($tenant);

        $recipient = $email ?? $tenant->users()->value('email');

        if (! is_string($recipient) || $recipient === '') {
            throw new RuntimeException('No recipient email for the billing setup link.');
        }

        Notification::route('mail', $recipient)
            ->notify(new BillingSetupLinkNotification($tenant, $url));

        return $url;
    }
}
