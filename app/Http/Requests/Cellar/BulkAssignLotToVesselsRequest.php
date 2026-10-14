<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAssignLotToVesselsRequest extends FormRequest
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
        $vessel = Rule::exists('vessels', 'id')->where('tenant_id', $tenantId);

        return [
            'vessel_ids' => ['required', 'array', 'min:1', 'max:100'],
            'vessel_ids.*' => ['required', 'string', 'distinct', $vessel],
            'mode' => ['required', Rule::in(['fill', 'split'])],
            'source' => ['required', Rule::in(['existing', 'new'])],
            'volume' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
