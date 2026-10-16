<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Set the same fields on many products. Stock is never bulk-edited — it moves only through the ledger. */
class BulkUpdateEnologicalProductsRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string', Rule::exists('enological_products', 'id')->where('tenant_id', $tenantId)],
            'fields' => ['required', 'array'],
            'fields.category' => ['sometimes', 'string', 'max:255'],
            'fields.supplier_id' => ['sometimes', 'nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'fields.min_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fields.cost_per_unit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fields.is_active' => ['sometimes', 'boolean'],
            'fields.manufacturer' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
