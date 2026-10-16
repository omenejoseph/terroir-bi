<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Draft purchase orders for protocol deficits — every deficit, or the lines given. */
class DraftEnologicalOrdersRequest extends FormRequest
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
        return [
            'lines' => ['sometimes', 'nullable', 'array'],
            'lines.*.enological_product_id' => ['required', 'string', Rule::exists('enological_products', 'id')->where('tenant_id', app(TenantContext::class)->id())],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
