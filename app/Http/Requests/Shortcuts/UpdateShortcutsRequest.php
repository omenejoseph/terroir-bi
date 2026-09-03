<?php

declare(strict_types=1);

namespace App\Http\Requests\Shortcuts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShortcutsRequest extends FormRequest
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
            'keys' => ['present', 'array'],
            'keys.*' => ['string'],
        ];
    }
}
