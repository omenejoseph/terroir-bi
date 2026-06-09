<?php

declare(strict_types=1);

namespace App\Http\Requests\Uploads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PresignUploadRequest extends FormRequest
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
        /** @var array<string, mixed> $purposes */
        $purposes = config('uploads.purposes', []);

        return [
            'purpose' => ['required', 'string', Rule::in(array_keys($purposes))],
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ];
    }
}
