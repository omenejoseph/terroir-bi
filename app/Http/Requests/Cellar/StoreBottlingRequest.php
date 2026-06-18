<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBottlingRequest extends FormRequest
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
            'bottle_count' => ['required', 'integer', 'min:1'],
            'bottle_volume_ml' => ['sometimes', 'integer', 'min:1'],
            'date' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string'],
            'inventory_item_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
