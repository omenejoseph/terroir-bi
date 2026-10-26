<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is inside the platform.admin group.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(SupportTicketStatus::values())],
            'priority' => ['sometimes', Rule::in(SupportTicketPriority::values())],
            'assignee_id' => [
                'sometimes', 'nullable', 'string',
                // A closure, so a real boolean is bound (Postgres rejects the string the plain where() serialises to).
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_platform_admin', true)),
            ],
        ];
    }
}
