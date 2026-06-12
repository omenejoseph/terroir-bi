<?php

namespace App\Filament\Resources\BddScenarios\Actions;

use App\Actions\Bdd\GrantBddOperationAction;
use App\Enums\BddRunStatus;
use App\Models\BddScenario;
use App\Queries\Bdd\BddScenarioRunsQuery;
use App\Services\Bdd\CurrentOperator;
use App\Services\Bdd\LiveScenarioRunner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Shared Filament glue for scenarios, used on table rows and view headers so
 * both stay identical. All logic lives in the Bdd services/actions.
 */
class ScenarioActions
{
    /** Execute the Gherkin live now (AI tool loop, sandboxed + rolled back) and show the verdict. */
    public static function run(): Action
    {
        return Action::make('runScenario')
            ->label('Run')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (BddScenario $record): bool => $record->isRunnable())
            ->requiresConfirmation()
            ->modalDescription('An AI agent executes the Gherkin live against a throwaway sandbox (always rolled back). Costs one AI call.')
            ->action(function (BddScenario $record): void {
                try {
                    $run = app(LiveScenarioRunner::class)->run($record, CurrentOperator::id());
                } catch (Throwable $e) {
                    Notification::make()->title('Run crashed')->body($e->getMessage())->danger()->send();

                    return;
                }

                $failing = collect($run->step_results ?? [])
                    ->first(fn (array $step): bool => ! in_array($step['status'] ?? '', ['pass', 'info'], true));
                $body = $run->status === BddRunStatus::Pass
                    ? count($run->step_results ?? []).' steps in '.$run->duration_ms.'ms — sandbox rolled back.'
                    : ($failing !== null
                        ? 'Step '.($failing['index'] ?? '?').': '.($failing['detail'] ?? '')
                        : (string) $run->error);

                Notification::make()
                    ->title('Scenario '.$run->status->value)
                    ->body($body)
                    ->{$run->status === BddRunStatus::Pass ? 'success' : 'danger'}()
                    ->send();
            });
    }

    /** One grant button covering every operation the latest run was denied. */
    public static function grantRequested(): Action
    {
        $denied = fn (BddScenario $record): array => app(BddScenarioRunsQuery::class)->latestDeniedOperations($record);

        return Action::make('grantRequestedAccess')
            ->label('Grant requested access')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('warning')
            ->visible(fn (BddScenario $record): bool => $denied($record) !== [])
            ->requiresConfirmation()
            ->modalDescription(fn (BddScenario $record): string => 'Grant: '
                .implode(', ', $denied($record))
                .' — the next run picks the grants up automatically.')
            ->action(function (BddScenario $record) use ($denied): void {
                $granted = [];
                foreach ($denied($record) as $operation) {
                    try {
                        app(GrantBddOperationAction::class)->execute($operation, CurrentOperator::id());
                        $granted[] = $operation;
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Could not grant '.$operation)
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }

                if ($granted !== []) {
                    Notification::make()
                        ->title('Access granted')
                        ->body(implode(', ', $granted).' — run the scenario again.')
                        ->success()
                        ->send();
                }
            });
    }
}
