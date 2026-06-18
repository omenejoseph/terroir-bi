<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\WineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFermentationTemplateRequest extends FormRequest
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
            'wine_type' => ['sometimes', 'nullable', Rule::enum(WineType::class)],
            'yeast_strain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'target_temp_min' => ['sometimes', 'nullable', 'numeric'],
            'target_temp_max' => ['sometimes', 'nullable', 'numeric'],
            'punchdown_schedule' => ['sometimes', 'nullable', 'string', 'max:255'],
            'maceration' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nutrients' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mlf' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string'],
            'estimated_duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'stages' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
