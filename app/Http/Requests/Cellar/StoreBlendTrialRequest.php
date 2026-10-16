<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A blend trial to keep: the same lots-and-ratios shape the projection takes, plus a name. */
class StoreBlendTrialRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'components' => ['required', 'array', 'min:2', 'max:20'],
            'components.*.wine_lot_id' => ['required', 'string', 'distinct', Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId)],
            'components.*.ratio' => ['required', 'numeric', 'gt:0', 'max:100'],
            'target_volume' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'destination_vessel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'projection' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
