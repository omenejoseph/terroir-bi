<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderCustomerCategoriesRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => [
                'string', 'distinct',
                Rule::exists('customer_categories', 'id')->where('tenant_id', app(TenantContext::class)->id()),
            ],
        ];
    }
}
