<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar\Activities;

use App\Http\Requests\Cellar\So2DoseRequest;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Validation\Rule;

/** "Plan for cellar team": an SO₂ dose request plus when and for whom. */
class StoreSo2RoundRequest extends So2DoseRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            ...parent::rules(),
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'planned_for' => ['required', 'date_format:Y-m-d'],
            'assigned_to_id' => ['sometimes', 'nullable', 'string', Rule::exists('memberships', 'user_id')->where('tenant_id', $tenantId)],
        ];
    }
}
