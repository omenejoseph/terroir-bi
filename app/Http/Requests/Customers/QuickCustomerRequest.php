<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuickCustomerRequest extends FormRequest
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
            'email' => [
                'required', 'email',
                Rule::unique('customers', 'email')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
