<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\InventoryItem;
use App\Support\StockUnits;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Validation\Validator;
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

    /** A minimum stock for bottles, cases or units is a whole number. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $rows = (array) $this->input('items', []);
            $units = InventoryItem::query()->whereIn('id', array_filter(array_column($rows, 'id'), 'is_string'))->pluck('unit', 'id');

            foreach ($rows as $index => $row) {
                if (($message = StockUnits::wholeError($units[$row['id'] ?? ''] ?? null, $row['min_stock'] ?? null)) !== null) {
                    $v->errors()->add("items.{$index}.min_stock", $message);
                }
            }
        });
    }
}
