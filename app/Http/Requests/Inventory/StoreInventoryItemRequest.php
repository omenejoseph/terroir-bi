<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryCategory;
use App\Enums\SalesUnit;
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

        // sales_unit + bottles_per_case only apply to bottle/case items (wine);
        // other units (kg, litre, gram, …) are priced per that unit directly.
        $unit = strtolower((string) $this->input('unit'));
        $isPackaged = in_array($unit, ['bottle', 'bottles', 'case', 'cases'], true);

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
            'unit_size' => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit' => ['required', 'string', 'max:50'],
            'sales_unit' => [Rule::requiredIf($isPackaged), 'nullable', Rule::enum(SalesUnit::class)],
            'min_stock' => ['sometimes', 'nullable', 'numeric'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'default_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'bottles_per_case' => [Rule::requiredIf($isPackaged), 'nullable', 'integer', 'min:1'],
            'pack_size' => ['sometimes', 'integer', 'min:1'],
            'is_for_sale' => ['sometimes', 'boolean'],
            'hide_from_portal' => ['sometimes', 'boolean'],
            'base_product_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
            ],
            // Optional — COGS can instead be derived from the item's recipe.
            'cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
