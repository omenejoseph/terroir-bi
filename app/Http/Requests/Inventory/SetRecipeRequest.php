<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetRecipeRequest extends FormRequest
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
            'items' => ['present', 'array'],
            'items.*.input_id' => [
                'required', 'string',
                // Inputs must belong to the same tenant, and not be the item itself.
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
                'not_in:'.$itemId,
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
