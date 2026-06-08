<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryCategory;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryItemRequest extends FormRequest
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
            'sku' => [
                'required', 'string', 'max:255',
                Rule::unique('inventory_items', 'sku')->where('tenant_id', $tenantId),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['required', Rule::enum(InventoryCategory::class)],
            'group' => ['sometimes', 'nullable', 'string'],
            'subcategory' => ['sometimes', 'nullable', 'string'],
            'vintage' => ['sometimes', 'nullable', 'string'],
            'unit' => ['required', 'string', 'max:50'],
            'min_stock' => ['sometimes', 'nullable', 'numeric'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'default_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'bottles_per_case' => ['sometimes', 'integer', 'min:1'],
            'is_for_sale' => ['sometimes', 'boolean'],
            'cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
