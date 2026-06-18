<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignVesselRequest extends FormRequest
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
            'vessel_id' => [
                'required', 'string',
                Rule::exists('vessels', 'id')->where('tenant_id', $tenantId),
            ],
            'volume' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
