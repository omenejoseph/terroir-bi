<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\VesselStatus;
use App\Enums\VesselType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVesselRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(VesselType::class)],
            'material' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity_liters' => ['sometimes', 'numeric', 'gt:0'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(VesselStatus::class)],
            'is_active' => ['sometimes', 'boolean'],
            'is_faulty' => ['sometimes', 'boolean'],
            'fault_note' => ['sometimes', 'nullable', 'string'],
            'room' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
