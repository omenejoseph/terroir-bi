<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachInventoryDocumentRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:1024'],
            'name' => ['required', 'string', 'max:255'],
            'content_type' => ['required', Rule::in((array) config('uploads.purposes.inventory_document.types', []))],
        ];
    }
}
