<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Actions\Tenancy\CreateTenantAction;
use App\Filament\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /** Back to the list after creating, not into the edit form. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Provisions the tenant (+ settings + first admin) through the shared action.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $result = app(CreateTenantAction::class)->execute([
            'name' => (string) $data['name'],
            'slug' => (string) $data['slug'],
            'currency' => (string) ($data['currency'] ?? 'EUR'),
            'locale' => (string) ($data['locale'] ?? 'hr'),
            'plan_id' => is_string($data['plan_id'] ?? null) ? $data['plan_id'] : null,
            'admin' => [
                'first_name' => (string) $data['admin_first_name'],
                'last_name' => (string) $data['admin_last_name'],
                'email' => (string) $data['admin_email'],
                'password' => (string) $data['admin_password'],
            ],
        ]);

        return $result['tenant'];
    }
}
