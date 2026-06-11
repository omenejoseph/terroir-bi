<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Actions\Billing\CreateBillingCheckoutLinkAction;
use App\Actions\Billing\SendBillingSetupLinkAction;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionAccessService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Throwable;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('plan.name')->label('Plan')->placeholder('—')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('access')
                    ->label('Access')
                    ->badge()
                    ->getStateUsing(fn (Tenant $record): string => app(SubscriptionAccessService::class)
                        ->compute($record, $record->subscription, $record->plan, Carbon::now())
                        ->level->value),
                TextColumn::make('subscription.stripe_status')->label('Stripe')->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),
                // Generate the hosted Stripe Checkout (onboarding) link and show it
                // to the admin to copy/open — does not email the customer.
                Action::make('generateOnboardingLink')
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
                    }),
                // Generate the same link and email it to the customer.
                Action::make('sendBillingLink')
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
                    }),
            ]);
    }
}
