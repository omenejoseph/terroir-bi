<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\CustomerCategory;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerCategoryRequest extends FormRequest
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
        $category = $this->route('category');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('customer_categories', 'name')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($category instanceof CustomerCategory ? $category->getKey() : null),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
