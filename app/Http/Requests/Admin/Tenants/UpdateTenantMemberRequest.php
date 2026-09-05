<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Tenants;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Mirrors the Filament relation manager's member EditAction: roles + status
 * only — a member's identity (name/email/password) isn't editable here.
 */
class UpdateTenantMemberRequest extends FormRequest
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
            'roles' => ['array'],
            'roles.*' => [Rule::in(array_map(fn (TenantRole $r) => $r->value, TenantRole::cases()))],
            'status' => ['required', new Enum(MembershipStatus::class)],
        ];
    }
}
