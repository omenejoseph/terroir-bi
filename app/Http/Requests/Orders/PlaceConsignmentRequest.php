<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\SalesUnit;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceConsignmentRequest extends FormRequest
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
            'items.*.inventory_item_id' => ['required', 'string', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_type' => ['sometimes', Rule::enum(SalesUnit::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
