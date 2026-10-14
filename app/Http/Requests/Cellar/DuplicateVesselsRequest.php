<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DuplicateVesselsRequest extends FormRequest
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
            'vessel_ids' => ['required', 'array', 'min:1', 'max:50'],
            'vessel_ids.*' => ['required', 'string', $vessel],
            'count' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
