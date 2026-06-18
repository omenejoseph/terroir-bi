<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\VesselType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVesselRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(VesselType::class)],
            'material' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity_liters' => ['required', 'numeric', 'gt:0'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'room' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_faulty' => ['sometimes', 'boolean'],
            'fault_note' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
