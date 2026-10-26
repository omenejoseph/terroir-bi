<?php

declare(strict_types=1);

namespace App\Http\Requests\Hospitality;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a reservation's details. An existing booking may stay on a program that has since been
 * retired, so unlike a new one this accepts any of the tenant's programs.
 */
class UpdateReservationRequest extends FormRequest
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
            'program_id' => ['sometimes', 'required', 'string', Rule::exists('hospitality_programs', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['sometimes', 'nullable', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d H:i'],
            'pax' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'guest_name' => ['sometimes', 'required', 'string', 'max:255'],
            'guest_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'guest_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'allergies' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
