<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Enums\AiImportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiImportRequest extends FormRequest
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
            'type' => ['required', Rule::enum(AiImportType::class)],
            // Object key returned by POST /uploads/presign (purpose: ai_import).
            'object_key' => ['required', 'string', 'max:1024'],
            'filename' => ['required', 'string', 'max:255'],
            'mime' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
