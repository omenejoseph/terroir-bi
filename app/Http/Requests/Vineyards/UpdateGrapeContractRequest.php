<?php

declare(strict_types=1);

namespace App\Http\Requests\Vineyards;

use App\Enums\GrapeContractStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGrapeContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'season' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(GrapeContractStatus::class)],
            'grape_variety' => ['sometimes', 'string', 'max:255'],
            'estimated_kg' => ['sometimes', 'numeric', 'min:0'],
            'delivered_kg' => ['sometimes', 'numeric', 'min:0'],
            'price_per_kg' => ['sometimes', 'integer', 'min:0'],
            'min_brix' => ['sometimes', 'nullable', 'numeric'],
            'max_ph' => ['sometimes', 'nullable', 'numeric'],
            'delivery_window' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
