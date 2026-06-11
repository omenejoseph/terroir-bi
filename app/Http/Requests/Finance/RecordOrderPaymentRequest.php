<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\InflowStatus;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordOrderPaymentRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:1'],
            'date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::enum(InflowStatus::class)],
            'is_credit_note' => ['sometimes', 'boolean'],
            'payment_method' => ['sometimes', 'nullable', Rule::enum(PaymentMethod::class)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
