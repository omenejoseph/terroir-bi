<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlendIntoLotRequest extends FormRequest
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
        $vessel = Rule::exists('vessels', 'id')->where('tenant_id', $tenantId);

        return [
            'from_vessel_id' => ['required', 'string', $vessel],
            'to_vessel_id' => ['required', 'string', $vessel],
            'volume' => ['required', 'numeric', 'gt:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
