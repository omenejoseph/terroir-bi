<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\Actions\CreateStripePriceAction;
use App\Filament\Resources\Plans\PlanResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPlan extends ViewRecord
{
    protected static string $resource = PlanResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateStripePriceAction::make(),
            EditAction::make(),
        ];
    }
}
