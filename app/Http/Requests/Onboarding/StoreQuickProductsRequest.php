<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Enums\InventoryCategory;
use App\Enums\SalesUnit;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuickProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Routes are gated by `can:settings.manage` and `can:inventory.manage`.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.product_category_id' => [
                'nullable', 'string',
                Rule::exists('product_categories', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('type', InventoryCategory::Finished->value),
            ],
            'rows.*.vintage' => ['nullable', 'string', 'max:20'],
            'rows.*.unit_size' => ['nullable', 'string', 'max:50'],
            'rows.*.sales_unit' => ['required', Rule::enum(SalesUnit::class)],
            'rows.*.bottles_per_case' => ['required', 'integer', 'min:1', 'max:1000'],
            // Minor units, per bottle — the same as the item form's list price.
            'rows.*.default_price' => ['nullable', 'integer', 'min:0'],
            // Bottles are counted whole.
            'rows.*.opening_stock' => ['nullable', 'integer', 'min:0'],
            'rows.*.is_for_sale' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rows.*.name' => __('name'),
            'rows.*.product_category_id' => __('category'),
            'rows.*.sales_unit' => __('sales unit'),
            'rows.*.bottles_per_case' => __('bottles per case'),
            'rows.*.default_price' => __('list price'),
            'rows.*.opening_stock' => __('opening stock'),
        ];
    }
}
