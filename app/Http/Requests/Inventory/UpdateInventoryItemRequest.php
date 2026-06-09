<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryCategory;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryItemRequest extends FormRequest
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
        $itemId = $this->route('item');
        $itemId = is_object($itemId) && method_exists($itemId, 'getKey') ? $itemId->getKey() : $itemId;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('inventory_items', 'sku')
                    ->where('tenant_id', $tenantId)
                    ->ignore($itemId),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', Rule::enum(InventoryCategory::class)],
            'group' => ['sometimes', 'nullable', 'string'],
            'subcategory' => ['sometimes', 'nullable', 'string'],
            'vintage' => ['sometimes', 'nullable', 'string'],
            'unit_size' => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'sales_unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'min_stock' => ['sometimes', 'nullable', 'numeric'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'default_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'bottles_per_case' => ['sometimes', 'integer', 'min:1'],
            'pack_size' => ['sometimes', 'integer', 'min:1'],
            'is_for_sale' => ['sometimes', 'boolean'],
            'hide_from_portal' => ['sometimes', 'boolean'],
            'base_product_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
                'not_in:'.$itemId,
            ],
            'cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
