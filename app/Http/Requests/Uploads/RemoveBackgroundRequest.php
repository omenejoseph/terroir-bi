<?php

declare(strict_types=1);

namespace App\Http\Requests\Uploads;

use Illuminate\Foundation\Http\FormRequest;

class RemoveBackgroundRequest extends FormRequest
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
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ];
    }
}
