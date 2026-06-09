<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\InflowStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInflowStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(InflowStatus::class)],
        ];
    }
}
