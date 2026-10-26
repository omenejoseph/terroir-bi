<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Enums\SupportTicketCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any member of the tenant may ask for help.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(SupportTicketCategory::values())],
            'body' => ['required', 'string', 'max:10000'],
            // Where in the app they were when they asked — a path, never an outside URL.
            'page_url' => ['nullable', 'string', 'max:2048', 'starts_with:/'],
        ];
    }
}
