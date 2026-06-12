<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Enums\AiImportLineStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiImportLineRequest extends FormRequest
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
            // A reviewer may approve, reject, edit, or reset a line to pending.
            'status' => ['required', Rule::enum(AiImportLineStatus::class)->except([AiImportLineStatus::Committed])],
            // Present when the reviewer edited the proposed fields.
            'edited_payload' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
