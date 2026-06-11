<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a tenant the hosted Stripe Checkout link to set up billing.
 */
class BillingSetupLinkNotification extends Notification
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $url,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Complete your '.(string) config('app.name').' billing setup')
            ->greeting('Hello,')
            ->line('Please set up billing for '.$this->tenant->name.' to activate your subscription.')
            ->action('Set up billing', $this->url)
            ->line('You can add a payment method and start your subscription from this secure link.');
    }
}
