<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTastingNoteRequest extends FormRequest
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
            'date' => ['sometimes', 'nullable', 'date'],
            'appearance' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nose' => ['sometimes', 'nullable', 'string', 'max:255'],
            'palate' => ['sometimes', 'nullable', 'string', 'max:255'],
            'overall' => ['sometimes', 'nullable', 'string', 'max:255'],
            'score' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'note' => ['sometimes', 'nullable', 'string'],
            'vessel_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('vessels', 'id')->where('tenant_id', $tenantId),
            ],
            'tasting_report_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('tasting_reports', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
