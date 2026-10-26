<?php

declare(strict_types=1);

namespace App\Http\Requests\Hospitality;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeReservationStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(ReservationStatus::class)],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
