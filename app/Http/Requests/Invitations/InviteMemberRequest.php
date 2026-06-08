<?php

declare(strict_types=1);

namespace App\Http\Requests\Invitations;

use App\Enums\TenantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class InviteMemberRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [new Enum(TenantRole::class)],
        ];
    }
}
