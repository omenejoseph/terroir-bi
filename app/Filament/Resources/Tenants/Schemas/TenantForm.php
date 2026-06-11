<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\DataTransferObjects\TenantAccessData;
use App\Enums\AccessLevel;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Queries\ListPlansQuery;
use App\Services\Billing\SubscriptionAccessService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255)->disabledOn('edit'),
                TextInput::make('slug')->required()->maxLength(255)->visibleOn('create'),
                Select::make('status')
                    ->options(collect(TenantStatus::cases())->mapWithKeys(fn (TenantStatus $s) => [$s->value => $s->name])->all())
                    ->default(TenantStatus::Trial->value)
                    ->required(),
                Select::make('plan_id')
                    ->label('Plan')
                    ->options(fn (): array => app(ListPlansQuery::class)->options())
                    ->searchable()
                    ->placeholder('No plan (unrestricted)'),

                // First-admin + locale, only when provisioning a new tenant.
                Section::make('First admin')
                    ->visibleOn('create')
                    ->columns(2)
                    ->schema([
                        TextInput::make('admin_first_name')->label('First name')->required(),
                        TextInput::make('admin_last_name')->label('Last name')->required(),
                        TextInput::make('admin_email')->label('Email')->email()->required(),
                        TextInput::make('admin_password')->label('Password')->password()->required()->minLength(8),
                        TextInput::make('currency')->default('EUR')->required()->maxLength(3),
                        TextInput::make('locale')->default('hr')->required()->maxLength(5),
                    ]),

                // Live Stripe / subscription state. Read-only here — Stripe owns it
                // (synced via webhooks); the table's onboarding/email/cancel actions
                // are how an admin drives it.
                Section::make('Stripe subscription')
                    ->description('Synced from Stripe via webhooks. Generate the onboarding link from the tenants list.')
                    ->visibleOn('edit')
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
