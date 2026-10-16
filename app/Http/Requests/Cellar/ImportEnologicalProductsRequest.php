<?php

declare(strict_types=1);

namespace App\Http\Requests\Cellar;

use Illuminate\Foundation\Http\FormRequest;

/** Pasted TSV/CSV of enological products; `dry_run` parses without saving. */
class ImportEnologicalProductsRequest extends FormRequest
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
            'text' => ['required', 'string', 'max:200000'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
