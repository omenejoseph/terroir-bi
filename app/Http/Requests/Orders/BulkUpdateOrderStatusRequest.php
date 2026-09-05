<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateOrderStatusRequest extends FormRequest
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
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => [
                'required', 'string',
                Rule::exists('orders', 'id')->where('tenant_id', $tenantId),
            ],
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
