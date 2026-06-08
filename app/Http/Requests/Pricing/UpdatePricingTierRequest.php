<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePricingTierRequest extends FormRequest
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
        $tier = $this->route('pricing_tier');
        $tierId = is_object($tier) && method_exists($tier, 'getKey') ? $tier->getKey() : $tier;

        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('pricing_tiers', 'name')->where('tenant_id', $tenantId)->ignore($tierId),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'rebate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
