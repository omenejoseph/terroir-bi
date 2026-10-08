<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerCategoryRequest extends FormRequest
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
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('customer_categories', 'name')->where('tenant_id', app(TenantContext::class)->id()),
            ],
        ];
    }
}
