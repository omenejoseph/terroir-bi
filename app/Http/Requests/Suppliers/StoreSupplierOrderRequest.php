<?php

declare(strict_types=1);

namespace App\Http\Requests\Suppliers;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierOrderRequest extends FormRequest
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
            'supplier_id' => ['required', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'expected_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
            'items.*.inventory_item_id' => ['sometimes', 'nullable', 'string', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
