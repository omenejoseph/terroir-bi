<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
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
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => [
                'required', 'email',
                Rule::unique('customers', 'email')->where('tenant_id', $tenantId),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string'],
            'state' => ['sometimes', 'nullable', 'string'],
            'zip' => ['sometimes', 'nullable', 'string'],
            'country' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'rebate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'hide_prices' => ['sometimes', 'boolean'],
            'exclude_from_stats' => ['sometimes', 'boolean'],
            'pricing_tier_id' => [
                'sometimes', 'nullable',
                Rule::exists('pricing_tiers', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
