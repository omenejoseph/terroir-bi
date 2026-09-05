<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\BddScenarios;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBddScenarioRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'gherkin' => ['required', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
