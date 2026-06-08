<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class UpsertPriceRequest extends FormRequest
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
            'price' => ['required', 'integer', 'min:0'], // money: integer minor units
        ];
    }
}
