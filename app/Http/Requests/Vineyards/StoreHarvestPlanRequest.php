<?php

declare(strict_types=1);

namespace App\Http\Requests\Vineyards;

use App\Enums\HarvestPlanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHarvestPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'season' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(HarvestPlanStatus::class)],
            'yield_ratio' => ['sometimes', 'numeric', 'gt:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
