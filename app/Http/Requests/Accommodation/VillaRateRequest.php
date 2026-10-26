<?php

declare(strict_types=1);

namespace App\Http\Requests\Accommodation;

use Illuminate\Foundation\Http\FormRequest;

class VillaRateRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'nightly_rate' => ['required', 'integer', 'min:0'], // money: minor units
            'min_nights' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
