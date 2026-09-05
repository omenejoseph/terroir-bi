<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\BddAccess;

use Illuminate\Foundation\Http\FormRequest;

/** Shared by BddAccessController's grant() and revoke() — both take just a `key`. */
class OperationKeyRequest extends FormRequest
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
            'key' => ['required', 'string'],
        ];
    }
}
