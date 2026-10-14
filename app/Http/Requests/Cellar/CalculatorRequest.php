<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\WineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Inputs for one of the enological calculators (Cellar › Calculators). */
class CalculatorRequest extends FormRequest
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
        $kind = (string) $this->input('kind');
        $num = ['required', 'numeric', 'min:0'];

        return [
            'kind' => ['required', Rule::in(['so2', 'acid', 'addition', 'chaptalization', 'press_yield', 'so2_compliance'])],
            ...match ($kind) {
                'so2' => ['volume_liters' => $num, 'current_free_so2' => $num, 'target_free_so2' => $num, 'ph' => ['required', 'numeric', 'between:2,5']],
                'acid' => ['volume_liters' => $num, 'current_ta' => $num, 'target_ta' => $num],
                'addition' => ['volume_liters' => $num, 'dose_mg_l' => $num, 'concentration_percent' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100']],
                'chaptalization' => ['volume_liters' => $num, 'current_brix' => $num, 'target_brix' => $num],
                'press_yield' => ['grape_kg' => $num, 'yield_ratio' => ['required', 'numeric', 'gt:0', 'max:1'], 'fractions' => ['required', 'array', 'min:1'], 'fractions.*.type' => ['required', 'string', 'max:50'], 'fractions.*.volume_liters' => $num],
                'so2_compliance' => ['total_so2' => $num, 'wine_type' => ['required', Rule::enum(WineType::class)], 'residual_sugar' => ['sometimes', 'nullable', 'numeric', 'min:0']],
                default => [],
            },
        ];
    }
}
