<?php

declare(strict_types=1);

namespace App\Http\Requests\Vineyards;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordIntakeRequest extends FormRequest
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
            'actual_yield_kg' => ['required', 'numeric', 'gt:0'],
            'actual_volume_liters' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'grape_price_per_kg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'grape_variety' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lot_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wine_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'vessel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'existing_lot_id' => ['sometimes', 'nullable', 'string', Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId)],
            'actual_date' => ['sometimes', 'nullable', 'date'],
            'brix' => ['sometimes', 'nullable', 'numeric'],
            'ph' => ['sometimes', 'nullable', 'numeric'],
            'titrable_acidity' => ['sometimes', 'nullable', 'numeric'],
            'temperature' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}
