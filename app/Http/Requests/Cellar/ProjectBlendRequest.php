<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\WineType;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A blend by lot ratios — for the projection, and (with a destination vessel)
 * for executing it. Size it with `target_volume`, or leave that out to use the
 * destination vessel's free room.
 */
class ProjectBlendRequest extends FormRequest
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
        $executing = str_ends_with($this->path(), 'blend/by-ratio');

        return [
            'components' => ['required', 'array', 'min:2', 'max:20'],
            'components.*.wine_lot_id' => ['required', 'string', 'distinct', Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId)],
            'components.*.ratio' => ['required', 'numeric', 'gt:0', 'max:100'],
            'target_volume' => ['required_without:destination_vessel_id', 'nullable', 'numeric', 'gt:0'],
            'destination_vessel_id' => [$executing ? 'required' : 'sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vintage' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wine_type' => ['sometimes', 'nullable', Rule::enum(WineType::class)],
        ];
    }
}
