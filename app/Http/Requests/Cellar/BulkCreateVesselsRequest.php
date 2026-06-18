<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use App\Enums\VesselType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkCreateVesselsRequest extends FormRequest
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
            'prefix' => ['sometimes', 'nullable', 'string', 'max:50'],
            'start_number' => ['sometimes', 'integer', 'min:0'],
            'count' => ['required', 'integer', 'min:1', 'max:50'],
            'type' => ['required', Rule::enum(VesselType::class)],
            'material' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity_liters' => ['required', 'numeric', 'gt:0'],
            'room' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
