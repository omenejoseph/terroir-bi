<?php

declare(strict_types=1);

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

/** A counted figure, or null to clear the line. */
class RecordKitchenCountRequest extends FormRequest
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
            'physical_count' => ['present', 'nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }
}
