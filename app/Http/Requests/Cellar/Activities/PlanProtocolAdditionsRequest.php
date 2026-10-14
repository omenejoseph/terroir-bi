<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar\Activities;

use Illuminate\Foundation\Http\FormRequest;

class PlanProtocolAdditionsRequest extends FormRequest
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
            'planned_for' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
