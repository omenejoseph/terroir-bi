<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\SalesUnit;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddOrderItemsRequest extends FormRequest
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

    /** Same rule as StoreOrderRequest: a custom (non-catalog) line needs its own description and price. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $items */
            $items = (array) $this->input('items', []);

            foreach ($items as $i => $item) {
                if (! empty($item['inventory_item_id'])) {
                    continue;
                }

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
