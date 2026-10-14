<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTastingReportRequest extends FormRequest
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
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string'],
            'wines' => ['sometimes', 'array', 'max:100'],
            'wines.*.wine_lot_id' => ['required', 'string', 'distinct', Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId)],
            'wines.*.vessel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'wines.*.score' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'wines.*.appearance' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wines.*.nose' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wines.*.palate' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wines.*.overall' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wines.*.note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
