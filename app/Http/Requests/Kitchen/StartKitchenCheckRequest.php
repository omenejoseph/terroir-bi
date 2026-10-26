<?php

declare(strict_types=1);

namespace App\Http\Requests\Kitchen;

use App\Enums\KitchenLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartKitchenCheckRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'location' => ['nullable', Rule::enum(KitchenLocation::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
