<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Actions\Billing\DeletePlanAction;
use App\Filament\Resources\Plans\Actions\CreateStripePriceAction;
use App\Models\Plan;
use App\Support\Money\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('modules')->badge()->limitList(3),
                TextColumn::make('price_minor')
                    ->label('Price')
                    ->placeholder('— free —')
                    ->formatStateUsing(fn (?Money $state, Plan $record): ?string => $state === null
                        ? null
                        : $state->toMajor().' '.$record->currency)
                    ->sortable(),
                TextColumn::make('stripe_price_id')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Stripe price')->placeholder('— free —'),
                TextColumn::make('trial_days')->label('Trial'),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('tenants_count')->label('Tenants'),
            ])
            ->recordActions([
                // First so the row click resolves to the read-only view.
                ViewAction::make(),
                CreateStripePriceAction::make(),
                EditAction::make(),
                // Deletion is routed through the action class, never inline.
                DeleteAction::make()->using(fn (Plan $record) => app(DeletePlanAction::class)->execute($record)),
            ]);
    }
}
