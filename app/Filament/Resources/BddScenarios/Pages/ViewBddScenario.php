<?php

namespace App\Filament\Resources\BddScenarios\Pages;

use App\Filament\Resources\BddScenarios\Actions\ScenarioActions;
use App\Filament\Resources\BddScenarios\BddScenarioResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBddScenario extends ViewRecord
{
    protected static string $resource = BddScenarioResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ScenarioActions::run(),
            ScenarioActions::grantRequested(),
            EditAction::make(),
        ];
    }
}
