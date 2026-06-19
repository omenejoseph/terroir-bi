<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateInventoryItemsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => [
                'required', 'string',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.name' => ['sometimes', 'string', 'max:255'],
            'items.*.min_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.default_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'items.*.cost_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'items.*.is_active' => ['sometimes', 'boolean'],
            'items.*.is_for_sale' => ['sometimes', 'boolean'],
        ];
    }
}
