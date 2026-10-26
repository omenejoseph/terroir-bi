<?php

declare(strict_types=1);

namespace App\Http\Requests\Accommodation;

use Illuminate\Foundation\Http\FormRequest;

class VillaSectionRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'content' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
