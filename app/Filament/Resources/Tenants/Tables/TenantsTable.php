<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Actions\Billing\SendBillingSetupLinkAction;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionAccessService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

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
                Action::make('sendBillingLink')
                    ->label('Send billing link')
                    ->requiresConfirmation()
                    ->visible(fn (Tenant $record): bool => $record->plan?->stripe_price_id !== null)
                    ->action(function (Tenant $record): void {
                        $url = app(SendBillingSetupLinkAction::class)->execute($record);
                        Notification::make()->title('Billing link sent')->body($url)->success()->send();
                    }),
            ]);
    }
}
