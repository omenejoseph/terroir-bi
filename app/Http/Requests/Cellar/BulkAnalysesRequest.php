<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Models\CellarAnalysis;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAnalysesRequest extends FormRequest
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

        $rules = [
            'analyses' => ['required', 'array', 'min:1'],
            'analyses.*.wine_lot_id' => [
                'required', 'string',
                Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId),
            ],
            'analyses.*.vessel_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('vessels', 'id')->where('tenant_id', $tenantId),
            ],
            'analyses.*.date' => ['sometimes', 'nullable', 'date'],
            'analyses.*.note' => ['sometimes', 'nullable', 'string'],
        ];

        foreach (CellarAnalysis::PARAMETERS as $param) {
            $rules["analyses.*.{$param}"] = ['sometimes', 'nullable', 'numeric'];
        }

        return $rules;
    }
}
