<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Support\StockUnits;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
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
            'type' => ['required', Rule::enum(StockMovementType::class)],
            'quantity' => ['required', 'numeric'], // signed delta
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_reconciliation' => ['sometimes', 'boolean'],
        ];
    }

    /** Bottles, cases and units move in whole pieces. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $item = $this->route('item');
            $quantity = $this->input('quantity');

            if ($item instanceof InventoryItem && StockUnits::isWhole($item->unit) && is_numeric($quantity) && ! StockUnits::isWholeNumber($quantity)) {
                $v->errors()->add('quantity', __('This item is counted in whole :unit — no decimals.', ['unit' => $item->unit]));
            }
        });
    }
}
