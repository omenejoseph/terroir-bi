<?php

namespace App\Filament\Resources\BddScenarios\Schemas;

use App\Models\BddScenario;
use App\Queries\Bdd\BddScenarioRunsQuery;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only scenario view: the Gherkin, compile state (incl. the access the
 * compiler asked for), the compiled steps with their last-run results, and the
 * run history below.
 */
class BddScenarioInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Scenario')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('last_run_status')->label('Last run')->badge()->placeholder('— never —'),
                        TextEntry::make('compile_model')->label('Compiled by')->placeholder('—'),
                        TextEntry::make('last_run_at')->dateTime()->placeholder('—'),
                        TextEntry::make('gherkin')
                            ->label('Gherkin')
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap text-sm']),
                    ]),

                Section::make('Access needed')
                    ->description('The compiler could not bind these steps — grant access (header button) and it recompiles automatically.')
                    ->visible(fn (BddScenario $record): bool => ($record->requested_operations ?? []) !== [])
                    ->schema([
                        TextEntry::make('requested_operations')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->state(fn (BddScenario $record): array => array_map(
                                fn (array $entry): string => ($entry['suggested_operation'] ?? '?')
                                    .(($entry['step_text'] ?? '') !== '' ? ' — for: "'.$entry['step_text'].'"' : ''),
                                $record->requested_operations ?? [],
                            )),
                    ]),

                Section::make('Compile error')
                    ->visible(fn (BddScenario $record): bool => $record->compile_error !== null
                        && ($record->requested_operations ?? []) === [])
                    ->schema([
                        TextEntry::make('compile_error')->hiddenLabel()->color('danger'),
                    ]),

                Section::make('Compiled steps')
                    ->description('The deterministic plan the runner replays — no AI at run time.')
                    ->visible(fn (BddScenario $record): bool => $record->compiled_plan !== null)
                    ->schema([
                        TextEntry::make('compiled_plan')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->state(fn (BddScenario $record): array => array_map(
                                fn (array $step): string => strtoupper((string) ($step['keyword'] ?? '')).' '
                                    .($step['text'] ?? '')
                                    .'  →  '.($step['op'] ?? ''),
                                $record->compiled_plan['steps'] ?? [],
                            ))
                            ->extraAttributes(['class' => 'font-mono text-sm']),
                    ]),

                Section::make('Last run detail')
                    ->visible(fn (BddScenario $record): bool => app(BddScenarioRunsQuery::class)->hasRuns($record))
                    ->schema([
                        TextEntry::make('last_run_steps')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->state(function (BddScenario $record): array {
                                $run = app(BddScenarioRunsQuery::class)->latest($record);
                                if ($run === null) {
                                    return [];
                                }
                                $lines = array_map(
                                    fn (array $step): string => sprintf(
                                        '%s step %s [%s] %s — %s',
                                        strtoupper((string) ($step['status'] ?? '')),
                                        $step['index'] ?? '?',
                                        $step['op'] ?? '',
                                        $step['text'] ?? '',
                                        $step['detail'] ?? '',
                                    ),
                                    $run->step_results ?? [],
                                );
                                if ($run->error !== null) {
                                    $lines[] = 'ERROR: '.$run->error;
                                }

                                return $lines;
                            })
                            ->extraAttributes(['class' => 'font-mono text-sm']),
                    ]),
            ]);
    }
}
