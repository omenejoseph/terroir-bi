<?php

namespace App\Filament\Resources\BddScenarios\Pages;

use App\Enums\BddRunStatus;
use App\Enums\BddScenarioStatus;
use App\Filament\Resources\BddScenarios\BddScenarioResource;
use App\Models\BddScenario;
use App\Services\Bdd\CurrentOperator;
use App\Services\Bdd\ScenarioRunner;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBddScenarios extends ListRecords
{
    protected static string $resource = BddScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Replay every active READY scenario, sandboxed + rolled back.
            Action::make('runAll')
                ->label('Run all')
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Run every active, compiled scenario against a throwaway sandbox (always rolled back).')
                ->action(function (): void {
                    $scenarios = BddScenario::query()
                        ->where('status', BddScenarioStatus::Ready->value)
                        ->where('is_active', true)
                        ->orderBy('title')
                        ->get();

                    if ($scenarios->isEmpty()) {
                        Notification::make()->title('No runnable scenarios')->warning()->send();

                        return;
                    }

                    $runner = app(ScenarioRunner::class);
                    $passed = 0;
                    $failedTitles = [];

                    foreach ($scenarios as $scenario) {
                        $run = $runner->run($scenario, CurrentOperator::id());
                        if ($run->status === BddRunStatus::Pass) {
                            $passed++;
                        } else {
                            $failedTitles[] = $scenario->title.' ('.$run->status->value.')';
                        }
                    }

                    Notification::make()
                        ->title("{$passed}/{$scenarios->count()} scenarios passed")
                        ->body($failedTitles === [] ? 'All green.' : 'Failing: '.implode('; ', $failedTitles))
                        ->{$failedTitles === [] ? 'success' : 'danger'}()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
