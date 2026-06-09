<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderShippingRequest extends FormRequest
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
            'shipping_cost' => ['present', 'nullable', 'integer', 'min:0'],
            'shipping_paid_by_us' => ['sometimes', 'boolean'],
        ];
    }
}
