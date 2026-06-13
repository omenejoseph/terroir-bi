<?php

namespace App\Filament\Resources\BddScenarios\Pages;

use App\Filament\Resources\BddScenarios\BddScenarioResource;
use App\Queries\Bdd\ListBddScenariosQuery;
use App\Services\Bdd\CurrentOperator;
use App\Services\Bdd\LiveScenarioRunner;
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
            // Execute every active scenario live, sandboxed + rolled back.
            Action::make('runAll')
                ->label('Run all')
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Queues a background run for every active scenario: an AI agent executes each Gherkin live against a throwaway sandbox (always rolled back). Costs one AI call per scenario.')
                ->action(function (): void {
                    $scenarios = app(ListBddScenariosQuery::class)->runnable();

                    if ($scenarios->isEmpty()) {
                        Notification::make()->title('No runnable scenarios')->warning()->send();

                        return;
                    }

                    $runner = app(LiveScenarioRunner::class);
                    foreach ($scenarios as $scenario) {
                        $runner->queue($scenario, CurrentOperator::id());
                    }

                    Notification::make()
                        ->title($scenarios->count().' runs queued')
                        ->body('Verdicts appear in the table as workers finish — it refreshes automatically.')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
