<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class BulkImportInventoryItemsRequest extends FormRequest
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
            // csv|txt: a CSV saved with a .txt extension (some spreadsheet
            // exports do this) is still just as importable.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], // 5 MB
        ];
    }
}
