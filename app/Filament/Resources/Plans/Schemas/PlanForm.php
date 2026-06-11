<?php

namespace App\Filament\Resources\Plans\Schemas;

use App\Enums\Module;
use App\Support\Money\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->required()->maxLength(255),
                TextInput::make('price_minor')
                    ->label('Price')
                    ->helperText('Amount billed per interval, in major units (e.g. 15.00). Leave blank for a free plan.')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->prefix(fn (Get $get): string => is_string($get('currency')) && $get('currency') !== '' ? $get('currency') : 'EUR')
                    // Stored as integer minor units (MoneyCast); shown/entered in major.
                    ->formatStateUsing(fn ($state): ?string => $state instanceof Money ? $state->toMajor() : $state)
                    ->dehydrateStateUsing(fn ($state, Get $get): ?int => $state === null || $state === ''
                        ? null
                        : Money::fromMajor((string) $state, is_string($get('currency')) && $get('currency') !== '' ? $get('currency') : 'EUR')->getMinorAmount()),
                TextInput::make('currency')->required()->default('EUR')->maxLength(3),
                CheckboxList::make('modules')
                    ->options(collect(Module::cases())->mapWithKeys(fn (Module $m) => [$m->value => $m->label()])->all())
                    ->columns(2)
                    ->helperText('Tenants on this plan see only the selected modules.'),
                TextInput::make('stripe_price_id')
                    ->label('Stripe price ID')
                    ->helperText('Leave blank for a free/internal plan.')
                    ->maxLength(255),
                TextInput::make('trial_days')->numeric()->default(0)->minValue(0)->required(),
                TextInput::make('grace_full_days')
                    ->label('Full-access grace (days)')
                    ->numeric()->default(0)->minValue(0)->required(),
                TextInput::make('grace_readonly_days')
                    ->label('Read-only grace (days)')
                    ->numeric()->default(0)->minValue(0)->required(),
                TextInput::make('interval')->default('month')->required()->maxLength(255),
                Toggle::make('is_active')->default(true),
                Toggle::make('is_public')->default(true),
            ]);
    }
}
