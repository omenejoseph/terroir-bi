<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreBottleAnalysisRequest extends FormRequest
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
        // Only the date is required; every measurement is optional.
        $measurement = ['sometimes', 'nullable', 'numeric'];

        return [
            'analyzed_on' => ['required', 'date'],
            'ph' => $measurement,
            'total_acidity' => $measurement,
            'volatile_acidity' => $measurement,
            'alcohol' => $measurement,
            'residual_sugar' => $measurement,
            'free_so2' => $measurement,
            'total_so2' => $measurement,
            'temperature' => $measurement,
            'density' => $measurement,
            'tpi' => $measurement,
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
