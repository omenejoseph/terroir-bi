<?php

declare(strict_types=1);

namespace App\Http\Requests\Vineyards;

use App\Enums\ParcelOwnership;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'grape_variety' => ['sometimes', 'string', 'max:255'],
            'area_hectares' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'elevation' => ['sometimes', 'nullable', 'integer'],
            'soil_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'planting_year' => ['sometimes', 'nullable', 'integer'],
            'row_spacing' => ['sometimes', 'nullable', 'numeric'],
            'vine_count' => ['sometimes', 'nullable', 'integer'],
            'rootstock' => ['sometimes', 'nullable', 'string', 'max:255'],
            'training' => ['sometimes', 'nullable', 'string', 'max:255'],
            'orientation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slope' => ['sometimes', 'nullable', 'numeric'],
            'latitude' => ['sometimes', 'nullable', 'numeric'],
            'longitude' => ['sometimes', 'nullable', 'numeric'],
            'geo_polygon' => ['sometimes', 'nullable', 'array'],
            'geo_area_calculated' => ['sometimes', 'nullable', 'numeric'],
            'ownership' => ['sometimes', Rule::enum(ParcelOwnership::class)],
            'cooperant_supplier_id' => ['sometimes', 'nullable', 'string', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
