<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Services\Auth\ImpersonationSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /** An admin impersonating a team member must never be able to change that member's password. */
    public function authorize(): bool
    {
        return app(ImpersonationSession::class)->get() === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ];
    }
}
