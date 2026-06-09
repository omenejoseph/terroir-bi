<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddOrderCommentRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();

        return [
            'content' => ['required', 'string'],
            'mentions' => ['sometimes', 'array'],
            'mentions.*' => [
                'string',
                Rule::exists('memberships', 'user_id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
