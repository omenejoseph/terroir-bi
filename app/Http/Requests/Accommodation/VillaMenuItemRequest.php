<?php

declare(strict_types=1);

namespace App\Http\Requests\Accommodation;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VillaMenuItemRequest extends FormRequest
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
            'villa_id' => ['required_unless:every_villa,true', 'nullable', 'string', Rule::exists('villas', 'id')->where('tenant_id', $tenantId)],
            'every_villa' => ['sometimes', 'boolean'],
            'category' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'integer', 'min:0'], // money: minor units
            'image' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'vat_included' => ['sometimes', 'boolean'],
            'inventory_item_id' => ['nullable', 'string', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
