<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar\Activities;

use App\Enums\CellarActivitySkipReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SkipCellarActivityRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(CellarActivitySkipReason::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
