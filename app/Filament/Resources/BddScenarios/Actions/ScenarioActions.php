<?php

namespace App\Filament\Resources\BddScenarios\Actions;

use App\Actions\Bdd\GrantBddOperationAction;
use App\Enums\BddRunStatus;
use App\Jobs\CompileBddScenarioJob;
use App\Models\BddScenario;
use App\Services\Bdd\CurrentOperator;
use App\Services\Bdd\ScenarioRunner;
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
    /** Replay the compiled plan now (sandboxed + rolled back) and show the verdict. */
    public static function run(): Action
    {
        return Action::make('runScenario')
            ->label('Run')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (BddScenario $record): bool => $record->status->isRunnable())
            ->action(function (BddScenario $record): void {
                try {
                    $run = app(ScenarioRunner::class)->run($record, CurrentOperator::id());
                } catch (Throwable $e) {
                    Notification::make()->title('Run crashed')->body($e->getMessage())->danger()->send();

                    return;
                }

                $failing = collect($run->step_results ?? [])->firstWhere('status', '!=', 'pass');
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

    /** Queue a fresh AI compile of the Gherkin (e.g. after app changes or new grants). */
    public static function recompile(): Action
    {
        return Action::make('recompileScenario')
            ->label('Recompile')
            ->icon(Heroicon::OutlinedSparkles)
            ->requiresConfirmation()
            ->modalDescription('Re-run the AI compiler against the current granted operations. Costs one AI call.')
            ->action(function (BddScenario $record): void {
                CompileBddScenarioJob::dispatch($record->getKey());

                Notification::make()
                    ->title('Compilation queued')
                    ->body('The scenario recompiles in the background; refresh to see its new status.')
                    ->success()
                    ->send();
            });
    }

    /** One grant button per operation the compiler reported missing. */
    public static function grantRequested(): Action
    {
        return Action::make('grantRequestedAccess')
            ->label('Grant requested access')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('warning')
            ->visible(fn (BddScenario $record): bool => ($record->requested_operations ?? []) !== [])
            ->requiresConfirmation()
            ->modalDescription(fn (BddScenario $record): string => 'Grant: '
                .collect($record->requested_operations ?? [])->pluck('suggested_operation')->unique()->implode(', ')
                .' — then recompile this scenario.')
            ->action(function (BddScenario $record): void {
                $operations = collect($record->requested_operations ?? [])
                    ->pluck('suggested_operation')
                    ->filter()
                    ->unique();

                $granted = [];
                foreach ($operations as $operation) {
                    try {
                        app(GrantBddOperationAction::class)->execute((string) $operation, CurrentOperator::id());
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
                        ->body(implode(', ', $granted).' — recompilation queued.')
                        ->success()
                        ->send();
                }
            });
    }
}
