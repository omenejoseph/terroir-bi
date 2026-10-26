<?php

declare(strict_types=1);

namespace App\Http\Requests\Accommodation;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A stay. Check-out must come after check-in (the old app accepted either order). `total_amount`
 * overrides the figure from the rates; `recalculate` asks for the rates to be applied again.
 */
class VillaBookingRequest extends FormRequest
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
            'villa_id' => ['required', 'string', Rule::exists('villas', 'id')->where('tenant_id', $tenantId)],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:64'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'guests' => ['required', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rebate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'total_amount' => ['nullable', 'integer', 'min:0'], // money: minor units
            'deposit_amount' => ['nullable', 'integer', 'min:0'],
            'deposit_paid' => ['sometimes', 'boolean'],
            'recalculate' => ['sometimes', 'boolean'],
        ];
    }
}
