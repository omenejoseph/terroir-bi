<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Actions\Billing\CreatePlanAction;
use App\Filament\Resources\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    /**
     * Persistence goes through the action — no DB call in the page.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreatePlanAction::class)->execute($data);
    }
}
