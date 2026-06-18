<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\WineLotStatus;
use App\Enums\WineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWineLotRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'grape_variety' => ['sometimes', 'string', 'max:255'],
            'vintage' => ['sometimes', 'string', 'max:255'],
            'vineyard' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wine_type' => ['sometimes', 'nullable', Rule::enum(WineType::class)],
            'status' => ['sometimes', Rule::enum(WineLotStatus::class)],
            'grape_price_per_kg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'harvest_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
