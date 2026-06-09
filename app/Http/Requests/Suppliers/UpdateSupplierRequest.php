<?php

declare(strict_types=1);

namespace App\Http\Requests\Suppliers;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
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
        $supplier = $this->route('supplier');
        $supplierId = is_object($supplier) && method_exists($supplier, 'getKey') ? $supplier->getKey() : $supplier;

        return [
            'company_name' => ['sometimes', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string'],
            'country' => ['sometimes', 'nullable', 'string'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('suppliers', 'tax_id')->where('tenant_id', $tenantId)->ignore($supplierId)],
            'bank_account' => ['sometimes', 'nullable', 'string', 'max:50'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'exclude_from_stats' => ['sometimes', 'boolean'],
        ];
    }
}
