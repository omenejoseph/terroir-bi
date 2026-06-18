<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVesselLayoutRequest extends FormRequest
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
            'updates' => ['required', 'array', 'min:1'],
            'updates.*.id' => [
                'required', 'string',
                Rule::exists('vessels', 'id')->where('tenant_id', $tenantId),
            ],
            'updates.*.position_x' => ['sometimes', 'nullable', 'integer'],
            'updates.*.position_y' => ['sometimes', 'nullable', 'integer'],
            'updates.*.map_width' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'updates.*.map_height' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'updates.*.rotation' => ['sometimes', 'nullable', 'integer'],
            'updates.*.room' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
