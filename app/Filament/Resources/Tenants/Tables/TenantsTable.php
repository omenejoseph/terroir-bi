<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Filament\Resources\Tenants\Actions\TenantBillingActions;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionAccessService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                // First so the row click resolves to the read-only view.
                ViewAction::make(),
                EditAction::make(),
                TenantBillingActions::generateOnboardingLink(),
                TenantBillingActions::emailBillingLink(),
            ]);
    }
}
