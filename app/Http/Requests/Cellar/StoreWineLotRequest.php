<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\WineLotStatus;
use App\Enums\WineType;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWineLotRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'grape_variety' => ['required', 'string', 'max:255'],
            'vintage' => ['required', 'string', 'max:255'],
            'vineyard' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wine_type' => ['sometimes', 'nullable', Rule::enum(WineType::class)],
            'initial_volume' => ['required', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::enum(WineLotStatus::class)],
            'grape_price_per_kg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'harvest_weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'vessel_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('vessels', 'id')->where('tenant_id', $tenantId),
            ],
            'grapes' => ['sometimes', 'array'],
            'grapes.*.grape_variety' => ['required_with:grapes', 'string', 'max:255'],
            'grapes.*.percentage' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'grapes.*.price_per_kg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'grapes.*.weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
