<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar\Activities;

use App\Models\CellarAnalysis;
use Illuminate\Foundation\Http\FormRequest;

class CompleteCellarActivityRequest extends FormRequest
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
        $rules = [
            'quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'liters' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'values' => ['sometimes', 'nullable', 'array'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
        foreach (CellarAnalysis::PARAMETERS as $param) {
            $rules["values.{$param}"] = ['sometimes', 'nullable', 'numeric'];
        }

        return $rules;
    }
}
