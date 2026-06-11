<?php

declare(strict_types=1);

namespace App\Http\Requests\Suppliers;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpsertPriceItemsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
            'items.*.unit' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
