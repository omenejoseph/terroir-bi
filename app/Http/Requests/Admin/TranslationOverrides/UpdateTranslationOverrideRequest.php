<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\TranslationOverrides;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTranslationOverrideRequest extends FormRequest
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
            'locale' => ['required', Rule::in(['hr', 'en'])],
            'key' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string'],
        ];
    }
}
