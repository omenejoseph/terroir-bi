<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Actions\Billing\DeletePlanAction;
use App\Actions\Billing\UpdatePlanAction;
use App\Filament\Resources\Plans\PlanResource;
use App\Models\Plan;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (Plan $record) => app(DeletePlanAction::class)->execute($record)),
        ];
    }

    /**
     * Persistence goes through the action — no DB call in the page.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Plan $record */
        return app(UpdatePlanAction::class)->execute($record, $data);
    }

    /**
     * Money attribute → editable minor-units int for the form (view mapping only).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Plan $record */
        $record = $this->getRecord();
        $data['price_minor'] = $record->price_minor?->getMinorAmount();

        return $data;
    }
}
