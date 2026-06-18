<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAdditionsRequest extends FormRequest
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
            'unit' => ['required', 'string', 'max:50'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string'],
            'enological_product_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('enological_products', 'id')->where('tenant_id', $tenantId),
            ],
            'lots' => ['required', 'array', 'min:1'],
            'lots.*.wine_lot_id' => [
                'required', 'string',
                Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId),
            ],
            'lots.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
