<?php

declare(strict_types=1);

namespace App\Http\Requests\Suppliers;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceItemRequest extends FormRequest
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
            'description' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', 'integer', 'min:0'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'inventory_item_id' => ['sometimes', 'nullable', 'string', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
