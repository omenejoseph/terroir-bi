<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Tenants;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mirrors App\Filament\Resources\Tenants\Schemas\TenantForm's create-only shape. */
class StoreTenantRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255'],
            'plan_id' => ['nullable', Rule::exists((new Plan)->getTable(), 'id')],
            'admin_first_name' => ['required', 'string', 'max:255'],
            'admin_last_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email'],
            'admin_password' => ['required', 'string', 'min:8'],
            'currency' => ['required', 'string', 'max:3'],
            'locale' => ['required', 'string', 'max:10'],
        ];
    }
}
