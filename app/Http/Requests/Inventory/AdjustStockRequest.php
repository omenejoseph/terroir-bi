<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
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
            'type' => ['required', Rule::enum(StockMovementType::class)],
            'quantity' => ['required', 'numeric'], // signed delta
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_reconciliation' => ['sometimes', 'boolean'],
        ];
    }
}
