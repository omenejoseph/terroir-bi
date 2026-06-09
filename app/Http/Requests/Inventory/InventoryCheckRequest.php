<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Tenancy\Contracts\TenantContext;
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
}
