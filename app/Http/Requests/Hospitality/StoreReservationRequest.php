<?php

declare(strict_types=1);

namespace App\Http\Requests\Hospitality;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new reservation. Program, date and head count are what the booking needs; the guest's name is
 * required too, as the old app's form required it. Price and total are never accepted from the
 * client — ReservationPricing decides them. The program must still be active to take new bookings.
 */
class StoreReservationRequest extends FormRequest
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
            'program_id' => ['required', 'string', Rule::exists('hospitality_programs', 'id')->where('tenant_id', $tenantId)->where(fn ($query) => $query->where('is_active', true))],
            'customer_id' => ['nullable', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'date' => ['required', 'date_format:Y-m-d H:i'],
            'pax' => ['required', 'integer', 'min:1', 'max:10000'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:64'],
            'allergies' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
