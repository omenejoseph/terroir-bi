<?php

declare(strict_types=1);

namespace App\Http\Requests\Vineyards;

use App\Enums\IntakeBookingStatus;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntakeBookingRequest extends FormRequest
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
            'harvest_plan_id' => ['sometimes', 'nullable', 'string', Rule::exists('harvest_plans', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['sometimes', 'nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'date' => ['required', 'date'],
            'time_slot' => ['sometimes', 'nullable', 'string', 'max:255'],
            'grape_variety' => ['sometimes', 'nullable', 'string', 'max:255'],
            'estimated_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'grower_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(IntakeBookingStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
