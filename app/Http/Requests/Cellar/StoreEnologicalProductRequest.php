<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnologicalProductRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'current_stock' => ['sometimes', 'numeric', 'min:0'],
            'min_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'packaging_size' => ['sometimes', 'nullable', 'string', 'max:255'],
            'so2_uplift_per_unit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'supplier_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId),
            ],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
