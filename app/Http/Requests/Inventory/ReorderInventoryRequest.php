<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ReorderInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Either `ids` (items in a band, in order) or `category` + `entries` (groups / subcategories, in order).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required_without:entries', 'array', 'min:1'],
            'ids.*' => ['string'],
            'category' => ['required_with:entries', 'string', 'max:50'],
            'entries' => ['required_without:ids', 'array', 'min:1'],
            'entries.*.group' => ['required', 'string', 'max:255'],
            'entries.*.subcategory' => ['nullable', 'string', 'max:255'],
        ];
    }
}
