<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\DoseBasis;
use App\Enums\WineType;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFermentationTemplateRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'wine_type' => ['sometimes', 'nullable', Rule::enum(WineType::class)],
            'yeast_strain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'target_temp_min' => ['sometimes', 'nullable', 'numeric'],
            'target_temp_max' => ['sometimes', 'nullable', 'numeric'],
            'punchdown_schedule' => ['sometimes', 'nullable', 'string', 'max:255'],
            'maceration' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nutrients' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mlf' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string'],
            'estimated_duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'stages' => ['sometimes', 'nullable', 'array'],
            'additives' => ['sometimes', 'array'],
            'additives.*.name' => ['required', 'string', 'max:255'],
            'additives.*.enological_product_id' => ['sometimes', 'nullable', 'string', Rule::exists('enological_products', 'id')->where('tenant_id', app(TenantContext::class)->id())],
            'additives.*.dose' => ['required', 'numeric', 'gt:0'],
            'additives.*.basis' => ['required', Rule::enum(DoseBasis::class)],
            'additives.*.stage' => ['sometimes', 'nullable', 'string', 'max:255'],
            'additives.*.notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
