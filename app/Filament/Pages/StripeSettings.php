<?php

namespace App\Filament\Pages;

use App\Services\Billing\StripeGateway;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * Connection status for the Stripe integration. Credentials live in the server
 * environment (services.stripe.*) — this page never reads or writes secrets; it
 * only reports whether they're set and lets an admin run a live "test connection"
 * to confirm the keys actually reach a Stripe account.
 */
class StripeSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.stripe-settings';

    /**
     * The account returned by the last successful "Test connection", as plain
     * values (Livewire-serializable; the Stripe SDK never reaches the view).
     *
     * @var array<string, scalar|null>|null
     */
    public ?array $account = null;

    public ?string $testError = null;

    public function getTitle(): string
    {
        return 'Stripe';
    }

    public function secretConfigured(): bool
    {
        return app(StripeGateway::class)->isConfigured();
    }

    public function webhookConfigured(): bool
    {
        return app(StripeGateway::class)->hasWebhookSecret();
    }

    public function successUrl(): string
    {
        return (string) config('services.stripe.success_url');
    }

    public function cancelUrl(): string
    {
        return (string) config('services.stripe.cancel_url');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedBolt)
                ->action(function (): void {
                    $this->account = null;
                    $this->testError = null;

                    $gateway = app(StripeGateway::class);

                    if (! $gateway->isConfigured()) {
                        $this->testError = 'No Stripe secret is configured (set STRIPE_SECRET in the environment).';
                        Notification::make()->title('Stripe is not configured')->danger()->send();

                        return;
                    }

                    try {
                        $snapshot = $gateway->retrieveAccount();
                    } catch (Throwable $e) {
                        $this->testError = $e->getMessage();
                        Notification::make()->title('Could not reach Stripe')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    $this->account = [
                        'id' => $snapshot->id,
                        'business_name' => $snapshot->businessName,
                        'country' => $snapshot->country,
                        'default_currency' => $snapshot->defaultCurrency,
                        'charges_enabled' => $snapshot->chargesEnabled,
                        'livemode' => $snapshot->livemode,
                    ];

                    Notification::make()
                        ->title('Connected to Stripe')
                        ->body('Account '.$snapshot->id.($snapshot->livemode ? ' (live mode)' : ' (test mode)'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
