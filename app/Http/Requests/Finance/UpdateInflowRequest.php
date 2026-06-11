<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\PaymentMethod;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInflowRequest extends FormRequest
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
            'customer_id' => ['sometimes', 'nullable', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'order_id' => ['sometimes', 'nullable', 'string', Rule::exists('orders', 'id')->where('tenant_id', $tenantId)],
            'date' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'integer', 'min:1'],
            'is_credit_note' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_method' => ['sometimes', 'nullable', Rule::enum(PaymentMethod::class)],
            'notes' => ['sometimes', 'nullable', 'string'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
