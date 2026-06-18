<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\PlanUnit;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductionPlanRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'rows' => ['sometimes', 'array'],
            'rows.*.base_item_id' => ['required', 'string', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'rows.*.quantity' => ['required', 'numeric', 'gt:0'],
            'rows.*.plan_unit' => ['required', Rule::enum(PlanUnit::class)],
            'rows.*.new_vintage' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rows.*.sort_order' => ['sometimes', 'integer'],
        ];
    }
}
