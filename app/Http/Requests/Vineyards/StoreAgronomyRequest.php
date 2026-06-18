<?php

declare(strict_types=1);

namespace App\Http\Requests\Vineyards;

use App\Enums\PhenologyStage;
use App\Enums\VineyardApplicationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for a parcel's agronomy records. The `kind` (route-supplied)
 * selects which fields apply; everything is optional/nullable so one request
 * serves maturity samples, phenology logs, crop estimates and applications.
 */
class StoreAgronomyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string'],
            // maturity
            'brix' => ['sometimes', 'nullable', 'numeric'],
            'ph' => ['sometimes', 'nullable', 'numeric'],
            'total_acidity' => ['sometimes', 'nullable', 'numeric'],
            'temperature' => ['sometimes', 'nullable', 'numeric'],
            // phenology
            'stage' => ['sometimes', Rule::enum(PhenologyStage::class)],
            'progress_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'photo_url' => ['sometimes', 'nullable', 'string'],
            // crop estimate
            'cluster_count' => ['sometimes', 'integer', 'min:0'],
            'avg_cluster_weight' => ['sometimes', 'numeric', 'min:0'],
            'sample_vine_count' => ['sometimes', 'integer', 'min:1'],
            // application
            'type' => ['sometimes', Rule::enum(VineyardApplicationType::class)],
            'product' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dosage' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phi_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'weather' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
