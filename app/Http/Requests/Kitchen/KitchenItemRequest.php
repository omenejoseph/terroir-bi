<?php

declare(strict_types=1);

namespace App\Http\Requests\Kitchen;

use App\Enums\KitchenCategory;
use App\Enums\KitchenLocation;
use App\Enums\KitchenUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adding or editing a kitchen item. Opening stock is only read when adding one. */
class KitchenItemRequest extends FormRequest
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
            'category' => ['required', Rule::enum(KitchenCategory::class)],
            'group' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', Rule::enum(KitchenUnit::class)],
            'location' => ['required', Rule::enum(KitchenLocation::class)],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'cost_per_unit' => ['nullable', 'integer', 'min:0'], // money: minor units
            'supplier' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'opening_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }
}
