<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\InventoryItem;
use App\Support\StockUnits;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryCheckRequest extends FormRequest
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
            'items' => ['present', 'array'],
            'items.*.item_id' => [
                'required', 'string',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
            ],
            // system_stock is accepted for the client's reference but the
            // adjustment is computed against the server's live stock.
            'items.*.system_stock' => ['sometimes', 'numeric'],
            'items.*.physical_count' => ['required', 'numeric', 'min:0'],
        ];
    }

    /** A count of bottles, cases or units is a whole number. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $lines = (array) $this->input('items', []);
            $units = InventoryItem::query()
                ->whereIn('id', array_filter(array_column($lines, 'item_id'), 'is_string'))
                ->pluck('unit', 'id');

            foreach ($lines as $index => $line) {
                $count = $line['physical_count'] ?? null;
                $unit = $units[$line['item_id'] ?? ''] ?? null;

                if (is_numeric($count) && StockUnits::isWhole($unit) && ! StockUnits::isWholeNumber($count)) {
                    $v->errors()->add("items.{$index}.physical_count", __('This item is counted in whole :unit — no decimals.', ['unit' => $unit]));
                }
            }
        });
    }
}
