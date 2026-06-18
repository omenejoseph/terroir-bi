<?php

declare(strict_types=1);

namespace App\Http\Requests\Vineyards;

use App\Enums\PressFractionType;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePressFractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'fraction_type' => ['required', Rule::enum(PressFractionType::class)],
            'volume_liters' => ['required', 'numeric', 'gt:0'],
            'wine_lot_id' => ['sometimes', 'nullable', 'string', Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId)],
            'vessel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'yield_percent' => ['sometimes', 'nullable', 'numeric'],
            'press_program' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pressure_bar' => ['sometimes', 'nullable', 'numeric'],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
