<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingTierRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('pricing_tiers', 'name')->where('tenant_id', $tenantId),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'rebate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
