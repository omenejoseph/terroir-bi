<?php

declare(strict_types=1);

namespace App\Http\Requests\Hospitality;

use Illuminate\Foundation\Http\FormRequest;

class AddReservationNoteRequest extends FormRequest
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
            'note' => ['required', 'string', 'max:5000'],
        ];
    }
}
