<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An SO₂ dose request from the SO₂ screen — shared by preview, apply and
 * "plan for cellar team". See App\Services\Cellar\So2DosePlanner.
 */
class So2DoseRequest extends FormRequest
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
            'enological_product_id' => ['required', 'string', Rule::exists('enological_products', 'id')->where('tenant_id', $tenantId)],
            'mode' => ['sometimes', 'nullable', Rule::in(['add', 'target'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.wine_lot_id' => ['required', 'string', Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId)],
            'items.*.vessel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'items.*.mg_l' => ['required', 'numeric', 'min:0', 'max:200'],
        ];
    }
}
