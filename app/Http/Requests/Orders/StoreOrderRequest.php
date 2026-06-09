<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use App\Enums\SalesUnit;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
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
            'customer_id' => [
                'required', 'string',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'is_backorder' => ['sometimes', 'boolean'],
            'backorder_date' => ['sometimes', 'nullable', 'date'],
            'is_consignment' => ['sometimes', 'boolean'],
            'shipping_cost' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'shipping_paid_by_us' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => [
                'nullable', 'string',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_type' => ['sometimes', Rule::enum(SalesUnit::class)],
            'items.*.unit_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'items.*.custom_description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $items */
            $items = (array) $this->input('items', []);

            foreach ($items as $i => $item) {
                if (! empty($item['inventory_item_id'])) {
                    continue;
                }

                // Custom (non-catalog) line: needs a description and an explicit price.
                if (empty($item['custom_description'])) {
                    $validator->errors()->add("items.{$i}.custom_description", 'A custom line needs a description.');
                }
                if (! isset($item['unit_price'])) {
                    $validator->errors()->add("items.{$i}.unit_price", 'A custom line needs a unit price.');
                }
            }
        });
    }
}
