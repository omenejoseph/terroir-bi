<?php

namespace App\Filament\Resources\Plans\Actions;

use App\Actions\Billing\CreateStripePriceForPlanAction;
use App\Models\Plan;
use App\Services\Billing\StripeGateway;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Shared "Set price in Stripe" action, used on the plans table rows and the plan
 * view page header so both stay identical. Pushes the plan's amount to Stripe
 * (product + recurring price) and stores the new price id on the plan.
 */
class CreateStripePriceAction
{
    public static function make(): Action
    {
        return Action::make('createStripePrice')
            ->label('Set price in Stripe')
            ->icon(Heroicon::OutlinedCreditCard)
            ->requiresConfirmation()
            ->modalDescription('Create a Stripe product + recurring price from this plan\'s amount and link it to the plan.')
            ->visible(fn (Plan $record): bool => $record->stripe_price_id === null
                && $record->price_minor !== null
                && app(StripeGateway::class)->isConfigured())
            ->action(function (Plan $record): void {
                try {
                    $plan = app(CreateStripePriceForPlanAction::class)->execute($record);
                } catch (Throwable $e) {
                    Notification::make()->title('Could not create the Stripe price')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Stripe price created')->body((string) $plan->stripe_price_id)->success()->send();
            });
    }
}
