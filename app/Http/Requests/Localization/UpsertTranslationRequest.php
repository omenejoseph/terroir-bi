<?php

declare(strict_types=1);

namespace App\Http\Requests\Localization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization (ADMIN-only) will be enforced via policies once auth is wired.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(config('app.supported_locales', []))],
            'key' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string'],
        ];
    }
}
