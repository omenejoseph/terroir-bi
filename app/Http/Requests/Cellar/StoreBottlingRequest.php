<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\SalesUnit;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBottlingRequest extends FormRequest
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
            'bottle_count' => ['required', 'integer', 'min:1'],
            'new_item' => ['sometimes', 'nullable', 'array'],
            'new_item.name' => ['required_with:new_item', 'string', 'max:255'],
            'new_item.sku' => ['required_with:new_item', 'string', 'max:255', Rule::unique('inventory_items', 'sku')->where('tenant_id', app(TenantContext::class)->id())],
            'new_item.default_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'new_item.sales_unit' => ['sometimes', Rule::enum(SalesUnit::class)],
            'new_item.bottles_per_case' => ['sometimes', 'integer', 'min:1'],
            'bottle_volume_ml' => ['sometimes', 'integer', 'min:1'],
            'date' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string'],
            'inventory_item_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
