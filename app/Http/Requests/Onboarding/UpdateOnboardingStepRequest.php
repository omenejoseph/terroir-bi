<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOnboardingStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is gated by `can:settings.manage`; nothing extra to check here.
        return true;
    }

    /**
     * Exactly one of the two per request — the hub's Skip and Mark-as-done
     * buttons each send their own.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'skipped' => ['required_without:done', 'prohibits:done', 'boolean'],
            'done' => ['required_without:skipped', 'boolean'],
        ];
    }
}
