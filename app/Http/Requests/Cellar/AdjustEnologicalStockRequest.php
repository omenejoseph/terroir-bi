<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use Illuminate\Foundation\Http\FormRequest;

class AdjustEnologicalStockRequest extends FormRequest
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
            'delta' => ['required', 'numeric', 'not_in:0'],
        ];
    }
}
