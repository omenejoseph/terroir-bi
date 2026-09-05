<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Tenants;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

/** Mirrors App\Filament\Resources\Tenants\RelationManagers\MembersRelationManager's "Add member" form. */
class AddTenantMemberRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique((new User)->getTable(), 'email')],
            'password' => ['required', Password::min(8)],
            'roles' => ['array'],
            'roles.*' => [Rule::in(array_map(fn (TenantRole $r) => $r->value, TenantRole::cases()))],
            'status' => ['required', new Enum(MembershipStatus::class)],
        ];
    }
}
