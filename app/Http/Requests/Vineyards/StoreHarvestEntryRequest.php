<?php

declare(strict_types=1);

namespace App\Http\Requests\Vineyards;

use App\Enums\HarvestSource;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHarvestEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'parcel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vineyard_parcels', 'id')->where('tenant_id', $tenantId)],
            'contract_id' => ['sometimes', 'nullable', 'string', Rule::exists('grape_contracts', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['sometimes', 'nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'planned_vessel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'source' => ['sometimes', Rule::enum(HarvestSource::class)],
            'grape_variety' => ['sometimes', 'nullable', 'string', 'max:255'],
            'planned_date' => ['sometimes', 'nullable', 'date'],
            'estimated_yield_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
