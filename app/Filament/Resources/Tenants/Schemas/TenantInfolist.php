<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\DataTransferObjects\TenantAccessData;
use App\Enums\AccessLevel;
use App\Enums\Module;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionAccessService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * The read-only tenant view — the default click target from the tenants table.
 * Shows the lifecycle/plan and the live Stripe subscription state; mutations go
 * through Edit and the billing actions in the header.
 */
class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tenant')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('slug')->copyable(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('plan.name')
                            ->label('Plan')
                            ->badge()
                            ->placeholder('— no plan (unrestricted) —'),
                        TextEntry::make('plan_price')
                            ->label('Plan price')
                            ->state(fn (Tenant $record): string => $record->plan?->price_minor !== null
                                ? $record->plan->price_minor->toMajor().' '.$record->plan->currency.' / '.$record->plan->interval
                                : '— free / no plan —'),
                        TextEntry::make('plan_modules')
                            ->label('Plan modules')
                            ->badge()
                            ->state(fn (Tenant $record): array => array_map(fn (Module $m): string => $m->label(), $record->plan?->modules() ?? []))
                            ->placeholder('— all modules (no plan) —')
                            ->columnSpanFull(),
                    ]),

                // Live Stripe / subscription state. Read-only — Stripe owns it
                // (synced via webhooks); the header's onboarding/email actions are
                // how an admin drives it.
                Section::make('Stripe subscription')
                    ->description('Synced from Stripe via webhooks. Drive it with the billing actions above.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('access')
                            ->label('Effective access')
                            ->badge()
                            ->state(fn (Tenant $record): string => self::access($record)->level->value)
                            ->color(fn (Tenant $record): string => match (self::access($record)->level) {
                                AccessLevel::Full => 'success',
                                AccessLevel::ReadOnly => 'warning',
                                AccessLevel::Blocked => 'danger',
                            }),
                        TextEntry::make('subscription.stripe_status')
                            ->label('Stripe status')
                            ->badge()
                            ->placeholder('— no subscription —'),
                        TextEntry::make('subscription.stripe_customer_id')
                            ->label('Customer ID')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('subscription.stripe_subscription_id')
                            ->label('Subscription ID')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('subscription.stripe_price_id')
                            ->label('Price ID')
                            ->copyable()
                            ->placeholder('— free / internal —'),
                        TextEntry::make('subscription.trial_ends_at')
                            ->label('Trial ends')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('subscription.current_period_end')
                            ->label('Current period ends')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('subscription.canceled_at')
                            ->label('Canceled at')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),
            ]);
    }

    private static function access(Tenant $record): TenantAccessData
    {
        return app(SubscriptionAccessService::class)
            ->compute($record, $record->subscription, $record->plan, Carbon::now());
    }
}
