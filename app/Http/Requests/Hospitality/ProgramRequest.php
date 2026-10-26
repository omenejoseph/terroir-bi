<?php

declare(strict_types=1);

namespace App\Http\Requests\Hospitality;

use Illuminate\Foundation\Http\FormRequest;

/** Adding or editing a program. The same fields either way; an edit may also (de)activate it. */
class ProgramRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_mins' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'base_price' => ['required', 'integer', 'min:0'], // money: integer minor units, per person
            'max_pax' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
