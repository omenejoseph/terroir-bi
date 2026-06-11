<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\Actions\TenantBillingActions;
use App\Filament\Resources\Tenants\TenantResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            TenantBillingActions::generateOnboardingLink(),
            TenantBillingActions::emailBillingLink(),
            EditAction::make(),
        ];
    }
}
