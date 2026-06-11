<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Actions\Billing\CreateStripePriceForPlanAction;
use App\Actions\Billing\DeletePlanAction;
use App\Models\Plan;
use App\Services\Billing\StripeGateway;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('modules')->badge()->limitList(3),
                TextColumn::make('stripe_price_id')->label('Stripe price')->placeholder('— free —'),
                TextColumn::make('trial_days')->label('Trial'),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('tenants_count')->label('Tenants'),
            ])
            ->recordActions([
                // Push the plan's amount to Stripe and store the new price id. Only
                // offered when Stripe is connected, the plan has a price, and it is
                // not already billing on an existing Stripe price.
                Action::make('createStripePrice')
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
                    }),
                EditAction::make(),
                // Deletion is routed through the action class, never inline.
                DeleteAction::make()->using(fn (Plan $record) => app(DeletePlanAction::class)->execute($record)),
            ]);
    }
}
