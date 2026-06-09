<?php

declare(strict_types=1);

namespace App\Http\Requests\Costs;

use App\Enums\CostStatus;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCostRequest extends FormRequest
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
            'date' => ['sometimes', 'date'],
            'total_amount' => ['required', 'integer', 'min:1'],
            'vat_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'category' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(CostStatus::class)],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'supplier_id' => ['sometimes', 'nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'items' => ['sometimes', 'array'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
            'items.*.quantity' => ['sometimes', 'numeric', 'min:0'],
            'items.*.category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items.*.inventory_item_id' => ['sometimes', 'nullable', 'string', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
