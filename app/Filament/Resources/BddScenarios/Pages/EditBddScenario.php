<?php

namespace App\Filament\Resources\BddScenarios\Pages;

use App\Actions\Bdd\SaveBddScenarioAction;
use App\Filament\Resources\BddScenarios\BddScenarioResource;
use App\Models\BddScenario;
use App\Services\Bdd\CurrentOperator;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBddScenario extends EditRecord
{
    protected static string $resource = BddScenarioResource::class;

    /**
     * Updates route through the action — a Gherkin change re-queues compilation.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var BddScenario $record */
        return app(SaveBddScenarioAction::class)->execute([
            'title' => (string) $data['title'],
            'gherkin' => (string) $data['gherkin'],
            'is_active' => (bool) ($data['is_active'] ?? $record->is_active),
        ], CurrentOperator::id(), $record);
    }

    protected function getRedirectUrl(): string
    {
        return BddScenarioResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
