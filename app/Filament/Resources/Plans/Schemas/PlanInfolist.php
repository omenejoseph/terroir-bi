<?php

namespace App\Filament\Resources\Plans\Schemas;

use App\Enums\Module;
use App\Models\Plan;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The read-only plan view — the default click target from the plans table.
 */
class PlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('slug')->copyable(),
                        TextEntry::make('price_minor')
                            ->label('Price')
                            ->state(fn (Plan $record): string => $record->price_minor !== null
                                ? $record->price_minor->toMajor().' '.$record->currency.' / '.$record->interval
                                : 'Free / internal')
                            ->badge()
                            ->color(fn (Plan $record): string => $record->price_minor !== null ? 'primary' : 'gray'),
                        TextEntry::make('tenants_count')
                            ->label('Tenants on this plan')
                            ->state(fn (Plan $record): int => $record->tenants()->count()),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        IconEntry::make('is_public')->label('Public')->boolean(),
                    ]),

                Section::make('Modules')
                    ->description('Tenants on this plan see only these modules.')
                    ->schema([
                        TextEntry::make('modules')
                            ->hiddenLabel()
                            ->badge()
                            ->state(fn (Plan $record): array => array_map(fn (Module $m): string => $m->label(), $record->modules()))
                            ->placeholder('— no modules —'),
                    ]),

                Section::make('Billing')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('stripe_price_id')
                            ->label('Stripe price ID')
                            ->copyable()
                            ->placeholder('— free / internal —'),
                        TextEntry::make('trial_days')->label('Trial (days)'),
                        TextEntry::make('grace_full_days')->label('Full-access grace (days)'),
                        TextEntry::make('grace_readonly_days')->label('Read-only grace (days)'),
                    ]),
            ]);
    }
}
