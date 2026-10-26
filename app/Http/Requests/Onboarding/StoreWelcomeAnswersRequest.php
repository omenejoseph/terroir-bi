<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Actions\Onboarding\ApplyWelcomeAnswersAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWelcomeAnswersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is gated by `can:settings.manage`; nothing extra to check here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sells' => ['required', Rule::in(['wine', 'wine_and_more'])],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['string', Rule::in(array_keys(ApplyWelcomeAnswersAction::CHANNEL_TIERS))],
            'has_file' => ['sometimes', 'boolean'],
            'categories' => ['sometimes', 'array', 'max:20'],
            'categories.*.name' => ['required', 'string', 'max:100'],
            'categories.*.has_vintage' => ['sometimes', 'boolean'],
            'tiers' => ['sometimes', 'array', 'max:10'],
            'tiers.*.name' => ['required', 'string', 'max:255'],
            'tiers.*.rebate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
