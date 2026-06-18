<?php

declare(strict_types=1);

namespace App\Http\Requests\Vineyards;

use App\Enums\GrapeContractStatus;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGrapeContractRequest extends FormRequest
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
            'supplier_id' => ['required', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'parcel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vineyard_parcels', 'id')->where('tenant_id', $tenantId)],
            'season' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(GrapeContractStatus::class)],
            'grape_variety' => ['required', 'string', 'max:255'],
            'estimated_kg' => ['required', 'numeric', 'min:0'],
            'price_per_kg' => ['required', 'integer', 'min:0'],
            'min_brix' => ['sometimes', 'nullable', 'numeric'],
            'max_ph' => ['sometimes', 'nullable', 'numeric'],
            'delivery_window' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
