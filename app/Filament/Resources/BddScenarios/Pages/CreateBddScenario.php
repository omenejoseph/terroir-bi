<?php

namespace App\Filament\Resources\BddScenarios\Pages;

use App\Actions\Bdd\SaveBddScenarioAction;
use App\Filament\Resources\BddScenarios\BddScenarioResource;
use App\Services\Bdd\CurrentOperator;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBddScenario extends CreateRecord
{
    protected static string $resource = BddScenarioResource::class;

    /**
     * Creation routes through the action (which also queues the AI compile).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveBddScenarioAction::class)->execute([
            'title' => (string) $data['title'],
            'gherkin' => (string) $data['gherkin'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ], CurrentOperator::id());
    }

    protected function getRedirectUrl(): string
    {
        return BddScenarioResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
