<?php

declare(strict_types=1);

namespace App\Http\Requests\Kitchen;

use App\Enums\KitchenMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** IN / OUT take a positive amount; ADJUSTMENT takes the new stock figure, which may be zero. */
class AdjustKitchenStockRequest extends FormRequest
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
        $adjusting = $this->input('type') === KitchenMovementType::Adjustment->value;

        return [
            'type' => ['required', Rule::enum(KitchenMovementType::class)],
            'quantity' => ['required', 'numeric', $adjusting ? 'min:0' : 'gt:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
