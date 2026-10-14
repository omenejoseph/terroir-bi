<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar\Activities;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSo2RoundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'planned_for' => ['required', 'date_format:Y-m-d'],
            'assigned_to_id' => ['sometimes', 'nullable', 'string', Rule::exists('memberships', 'user_id')->where('tenant_id', $tenantId)],
            'enological_product_id' => ['sometimes', 'nullable', 'string', Rule::exists('enological_products', 'id')->where('tenant_id', $tenantId)],
            'product_name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.wine_lot_id' => ['required', 'string', Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId)],
            'items.*.vessel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.target_note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
