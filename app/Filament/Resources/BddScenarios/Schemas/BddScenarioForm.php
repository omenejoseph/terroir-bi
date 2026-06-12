<?php

namespace App\Filament\Resources\BddScenarios\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BddScenarioForm
{
    private const PLACEHOLDER = <<<'GHERKIN'
        Scenario: Creating an order deducts stock immediately
          Given "R3 2025" has 100 bottles in stock
          When a non-backorder order for 24 bottles of "R3 2025" is created
          Then an ORDER_DEDUCT movement of -24 bottles is recorded referencing the order number
          And current stock of "R3 2025" is 76 bottles
        GHERKIN;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('ORD-001 — Stock is committed at order creation'),
                Textarea::make('gherkin')
                    ->label('Gherkin')
                    ->required()
                    ->rows(14)
                    ->placeholder(self::PLACEHOLDER)
                    ->helperText('Plain Given/When/Then. On run, an AI agent executes it live against the granted operations (sandboxed, always rolled back) — money in EUR is fine ("€12.00"); the agent converts to minor units.')
                    ->extraInputAttributes(['class' => 'font-mono text-sm'])
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Active (included in "Run all")')
                    ->default(true),
            ]);
    }
}
