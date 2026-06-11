<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Actions\Tenancy\AssignPlanToTenantAction;
use App\Actions\Tenancy\UpdateTenantStatusAction;
use App\Enums\TenantStatus;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Edits route through the dedicated actions (status + plan) — no DB call in
     * the page. Name/slug are immutable here.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Tenant $record */
        app(UpdateTenantStatusAction::class)->execute($record, TenantStatus::from((string) $data['status']));
        app(AssignPlanToTenantAction::class)->execute(
            $record,
            is_string($data['plan_id'] ?? null) ? $data['plan_id'] : null,
        );

        return $record;
    }
}
