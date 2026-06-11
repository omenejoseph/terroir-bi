<?php

namespace App\Filament\Resources\Tenants\Actions;

use App\Actions\Billing\CreateBillingCheckoutLinkAction;
use App\Actions\Billing\SendBillingSetupLinkAction;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Shared billing actions for tenants, used on the tenants table rows and the
 * tenant view page header so both stay identical. The underlying logic lives in
 * the App\Actions\Billing classes — this is only Filament glue.
 */
class TenantBillingActions
{
    /**
     * A tenant needs to subscribe when it's on a paid plan (has a Stripe price)
     * but has no Stripe subscription yet — that's who the link is for.
     */
    private static function needsSubscription(Tenant $record): bool
    {
        return $record->plan?->stripe_price_id !== null
            && $record->subscription?->stripe_subscription_id === null;
    }

    /**
     * Generate the hosted Stripe Checkout (subscription) link and present it in a
     * modal with a copy button — does not email the customer. Shown only for
     * tenants that haven't subscribed yet.
     */
    public static function generateOnboardingLink(): Action
    {
        return Action::make('generateOnboardingLink')
            ->label('Generate subscription link')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (Tenant $record): bool => self::needsSubscription($record))
            ->modalHeading('Subscription link')
            ->modalDescription('Send this Stripe Checkout link to the tenant so they can subscribe.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            // Generate the link as the modal opens and pre-fill the copyable field.
            ->fillForm(function (Tenant $record): array {
                try {
                    return ['url' => app(CreateBillingCheckoutLinkAction::class)->execute($record)];
                } catch (Throwable $e) {
                    Notification::make()->title('Could not generate the subscription link')->body($e->getMessage())->danger()->send();

                    return ['url' => ''];
                }
            })
            ->schema([
                TextInput::make('url')
                    ->label('Subscription link')
                    ->readOnly()
                    ->copyable()
                    ->helperText('Copy and share it, or open it to preview the checkout.')
                    ->columnSpanFull(),
            ]);
    }

    /** Generate the same link and email it to the customer. */
    public static function emailBillingLink(): Action
    {
        return Action::make('sendBillingLink')
            ->label('Email subscription link')
            ->icon(Heroicon::OutlinedEnvelope)
            ->requiresConfirmation()
            ->visible(fn (Tenant $record): bool => self::needsSubscription($record))
            ->action(function (Tenant $record): void {
                try {
                    $url = app(SendBillingSetupLinkAction::class)->execute($record);
                } catch (Throwable $e) {
                    Notification::make()->title('Could not send the billing link')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Billing link sent')->body($url)->success()->send();
            });
    }
}
