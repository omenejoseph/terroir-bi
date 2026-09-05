<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Tenants;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignPlanRequest extends FormRequest
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
            'plan_id' => ['nullable', Rule::exists((new Plan)->getTable(), 'id')],
        ];
    }
}
