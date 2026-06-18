<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use Illuminate\Foundation\Http\FormRequest;

class RenameRoomRequest extends FormRequest
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
            'from' => ['required', 'string', 'max:255'],
            'to' => ['required', 'string', 'max:255'],
        ];
    }
}
