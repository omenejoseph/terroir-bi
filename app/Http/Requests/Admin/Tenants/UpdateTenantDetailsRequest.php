<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Tenants;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantDetailsRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // Globally unique per the tenants migration's own index, not
            // scoped to "every other tenant but this one" via a where clause.
            'slug' => ['required', 'string', 'max:255', Rule::unique('tenants', 'slug')->ignore($this->route('tenant'))],
            'default_locale' => ['required', 'string', Rule::in((array) config('app.supported_locales', []))],
        ];
    }
}
