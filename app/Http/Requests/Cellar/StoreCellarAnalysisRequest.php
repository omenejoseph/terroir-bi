<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Models\CellarAnalysis;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCellarAnalysisRequest extends FormRequest
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
            'date' => ['sometimes', 'nullable', 'date'],
            'vessel_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('vessels', 'id')->where('tenant_id', $tenantId),
            ],
            'note' => ['sometimes', 'nullable', 'string'],
        ];

        foreach (CellarAnalysis::PARAMETERS as $param) {
            $rules[$param] = ['sometimes', 'nullable', 'numeric'];
        }

        return $rules;
    }
}
