<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is gated by `can:settings.manage`; nothing extra to check here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Note: default_currency is intentionally omitted — it is read-only for
        // now (changing it would relabel stored minor-unit amounts, not convert).
        return [
            'name' => ['required', 'string', 'max:255'],
            'default_locale' => ['required', 'string', Rule::in(config('app.supported_locales', []))],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'company_oib' => ['nullable', 'string', 'max:32'],
        ];
    }
}
