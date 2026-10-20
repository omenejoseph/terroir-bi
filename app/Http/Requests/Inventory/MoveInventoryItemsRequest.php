<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveInventoryItemsRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string'],
            // Null drops the product out of any category (back to "Ungrouped").
            'product_category_id' => ['present', 'nullable', 'string', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
