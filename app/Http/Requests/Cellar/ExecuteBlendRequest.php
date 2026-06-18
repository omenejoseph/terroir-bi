<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\WineType;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteBlendRequest extends FormRequest
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
            'vintage' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wine_type' => ['sometimes', 'nullable', Rule::enum(WineType::class)],
            'destination_vessel_id' => [
                'required', 'string',
                Rule::exists('vessels', 'id')->where('tenant_id', $tenantId),
            ],
            'sources' => ['required', 'array', 'min:2'],
            'sources.*.vessel_lot_id' => [
                'required', 'string',
                Rule::exists('vessel_lots', 'id')->where('tenant_id', $tenantId),
            ],
            'sources.*.volume' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
