<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Correcting an addition: every field optional. */
class UpdateCellarAdditionRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'total_cost' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string'],
            'vessel_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('vessels', 'id')->where('tenant_id', $tenantId),
            ],
            'enological_product_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('enological_products', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
