<?php

declare(strict_types=1);

namespace App\Http\Requests\Invitations;

use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            // Required either way now: a new account's chosen password, or an
            // existing account's own password to confirm it's really them —
            // see AcceptInvitationAction.
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
