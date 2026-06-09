<?php

declare(strict_types=1);

namespace App\Http\Requests\Costs;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCostRequest extends FormRequest
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
            'total_amount' => ['sometimes', 'integer', 'min:1'],
            'vat_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'category' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'supplier_id' => ['sometimes', 'nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
