<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteVesselsRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:300'],
            'ids.*' => ['string'],
        ];
    }
}
