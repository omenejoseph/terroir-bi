<?php

declare(strict_types=1);

namespace App\Http\Requests\Hospitality;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The reservation form's live price preview. */
class QuoteReservationRequest extends FormRequest
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
            'program_id' => ['required', 'string', Rule::exists('hospitality_programs', 'id')->where('tenant_id', $tenantId)],
            'customer_id' => ['nullable', 'string', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'pax' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
