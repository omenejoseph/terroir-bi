<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryCategory;
use App\Enums\SalesUnit;
use App\Models\InventoryItem;
use App\Models\ProductCategory;
use App\Rules\ValidPreviousVintage;
use App\Support\StockUnits;
use App\Tenancy\Contracts\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\Validator;
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
            'sales_unit' => ['sometimes', Rule::enum(SalesUnit::class)],
            'min_stock' => ['sometimes', 'nullable', 'numeric'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'default_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'bottles_per_case' => ['sometimes', 'integer', 'min:1'],
            'pack_size' => ['sometimes', 'integer', 'min:1'],
            'is_for_sale' => ['sometimes', 'boolean'],
            'hide_from_portal' => ['sometimes', 'boolean'],
            'product_category_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId),
                $this->categoryMatchesType(),
            ],
            'previous_vintage_id' => ['sometimes', 'nullable', 'string', new ValidPreviousVintage(($this->route('item') instanceof InventoryItem ? $this->route('item') : null))],
            'base_product_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
                'not_in:'.$itemId,
            ],
            // Optional — can be cleared to fall back to the recipe roll-up for COGS.
            'cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /** Bottles, cases and units are counted in whole pieces, so a minimum stock for them is too. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $item = $this->route('item');
            $unit = $this->input('unit') ?? ($item instanceof InventoryItem ? $item->unit : null);

            if (($message = StockUnits::wholeError($unit, $this->input('min_stock'))) !== null) {
                $v->errors()->add('min_stock', $message);
            }
        });
    }

    /** The category an item is filed under must belong to the item's own type (finished, raw material, …). */
    private function categoryMatchesType(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $item = $this->route('item');
            $type = $this->input('category') ?? ($item instanceof InventoryItem ? $item->category->value : null);
            $category = is_string($value) ? ProductCategory::query()->find($value) : null;

            if ($category !== null && $type !== null && $category->type->value !== $type) {
                $fail(__('That category belongs to a different type of item.'));
            }
        };
    }
}
