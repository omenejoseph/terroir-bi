<?php

namespace App\Filament\Resources\BddScenarios\Schemas;

use App\Models\BddScenario;
use App\Queries\Bdd\BddScenarioRunsQuery;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only scenario view: the Gherkin (executed live by an AI agent on each
 * run), any access the latest run was denied, the latest run's step results,
 * and the full tool transcript for auditing the AI's judgements.
 */
class BddScenarioInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $runs = fn (): BddScenarioRunsQuery => app(BddScenarioRunsQuery::class);

        return $schema
            ->components([
                Section::make('Scenario')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('last_run_status')->label('Last run')->badge()->placeholder('— never —'),
                        TextEntry::make('last_run_at')->dateTime()->placeholder('—'),
                        TextEntry::make('gherkin')
                            ->label('Gherkin')
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap text-sm']),
                    ]),

                Section::make('Access needed')
                    ->description('The latest run hit operations that are not granted — grant access (header button) and run again.')
                    ->visible(fn (BddScenario $record): bool => $runs()->latestDeniedOperations($record) !== [])
                    ->schema([
                        TextEntry::make('denied_operations')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->state(fn (BddScenario $record): array => $runs()->latestDeniedOperations($record)),
                    ]),

                Section::make('Last run detail')
                    ->visible(fn (BddScenario $record): bool => $runs()->hasRuns($record))
                    ->schema([
                        TextEntry::make('last_run_steps')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->state(function (BddScenario $record) use ($runs): array {
                                $run = $runs()->latest($record);
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

                Section::make('Last run transcript')
                    ->description('Every tool call the AI made, with arguments and results — the audit trail behind the verdict.')
                    ->collapsed()
                    ->visible(fn (BddScenario $record): bool => ($runs()->latest($record)->transcript ?? []) !== [])
                    ->schema([
                        TextEntry::make('last_run_transcript')
                            ->hiddenLabel()
                            ->state(fn (BddScenario $record): string => (string) json_encode(
                                $runs()->latest($record)->transcript ?? [],
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ))
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap text-xs']),
                    ]),
            ]);
    }
}
