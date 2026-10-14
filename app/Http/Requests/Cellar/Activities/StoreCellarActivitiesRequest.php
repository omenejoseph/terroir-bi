<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar\Activities;

use App\Enums\CellarActivityType;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCellarActivitiesRequest extends FormRequest
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

        // Payload shape is validated per type by App\Services\Cellar\ActivityPayload.
        return [
            'round_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'assigned_to_id' => ['sometimes', 'nullable', 'string', Rule::exists('memberships', 'user_id')->where('tenant_id', $tenantId)],
            'activities' => ['required', 'array', 'min:1', 'max:500'],
            'activities.*.type' => ['required', Rule::enum(CellarActivityType::class)],
            'activities.*.planned_for' => ['required', 'date_format:Y-m-d'],
            'activities.*.wine_lot_id' => ['sometimes', 'nullable', 'string', Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId)],
            'activities.*.vessel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'activities.*.note' => ['sometimes', 'nullable', 'string'],
            'activities.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'activities.*.payload' => ['required', 'array'],
        ];
    }
}
