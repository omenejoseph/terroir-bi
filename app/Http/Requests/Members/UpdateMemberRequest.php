<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateMemberRequest extends FormRequest
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
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => [new Enum(TenantRole::class)],
            'status' => ['sometimes', Rule::enum(MembershipStatus::class)],
        ];
    }
}
