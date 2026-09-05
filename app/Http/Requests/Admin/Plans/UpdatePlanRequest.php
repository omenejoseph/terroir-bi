<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Plans;

use App\Enums\Module;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255'],
            'price_minor' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:3'],
            'modules' => ['array'],
            'modules.*' => [Rule::in(Module::values())],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
            'trial_days' => ['required', 'integer', 'min:0'],
            'grace_full_days' => ['required', 'integer', 'min:0'],
            'grace_readonly_days' => ['required', 'integer', 'min:0'],
            'interval' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
        ];
    }
}
