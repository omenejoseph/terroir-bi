<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\CellarTransferType;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
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
        $vessel = ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)];

        return [
            'type' => ['required', Rule::enum(CellarTransferType::class)],
            'volume_liters' => ['required', 'numeric', 'gt:0'],
            'to_lot_id' => [
                'required', 'string',
                Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId),
            ],
            'from_vessel_id' => $vessel,
            'to_vessel_id' => $vessel,
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
