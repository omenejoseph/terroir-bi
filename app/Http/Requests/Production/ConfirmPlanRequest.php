<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['force' => ['sometimes', 'boolean']];
    }
}
