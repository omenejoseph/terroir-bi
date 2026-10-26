<?php

declare(strict_types=1);

namespace App\Http\Requests\Accommodation;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The booking form's live price. */
class VillaBookingQuoteRequest extends FormRequest
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
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'rebate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
