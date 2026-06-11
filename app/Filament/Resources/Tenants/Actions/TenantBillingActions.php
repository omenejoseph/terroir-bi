<?php

namespace App\Filament\Resources\Tenants\Actions;

use App\Actions\Billing\CreateBillingCheckoutLinkAction;
use App\Actions\Billing\SendBillingSetupLinkAction;
use App\Models\Tenant;
use Filament\Actions\Action;
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
     * Generate the hosted Stripe Checkout (onboarding) link and show it to the
     * admin to copy/open — does not email the customer.
     */
    public static function generateOnboardingLink(): Action
    {
        return Action::make('generateOnboardingLink')
            ->label('Onboarding link')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (Tenant $record): bool => $record->plan?->stripe_price_id !== null)
            ->action(function (Tenant $record): void {
                try {
                    $url = app(CreateBillingCheckoutLinkAction::class)->execute($record);
                } catch (Throwable $e) {
                    Notification::make()->title('Could not generate the onboarding link')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Onboarding link ready')
                    ->body($url)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /** Generate the same link and email it to the customer. */
    public static function emailBillingLink(): Action
    {
        return Action::make('sendBillingLink')
            ->label('Email billing link')
            ->icon(Heroicon::OutlinedEnvelope)
            ->requiresConfirmation()
            ->visible(fn (Tenant $record): bool => $record->plan?->stripe_price_id !== null)
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
