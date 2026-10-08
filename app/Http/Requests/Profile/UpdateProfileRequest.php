<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Services\Auth\ImpersonationSession;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    /** Someone impersonating a team member must not be able to rewrite that person's account. */
    public function authorize(): bool
    {
        return app(ImpersonationSession::class)->get() === null;
    }

    /**
     * Email is deliberately not editable here: it is the sign-in identity shared across every
     * organisation the person belongs to, so changing it needs its own verification flow.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ];
    }
}
