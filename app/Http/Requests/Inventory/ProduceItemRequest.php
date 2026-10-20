<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\InventoryItem;
use App\Support\StockUnits;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ProduceItemRequest extends FormRequest
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
            'display_quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /** Producing 1.5 bottles is not a thing; a measured output (litres, kg) can be fractional. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $item = $this->route('item');
            $message = $item instanceof InventoryItem ? StockUnits::wholeError($item->unit, $this->input('display_quantity')) : null;

            if ($message !== null) {
                $v->errors()->add('display_quantity', $message);
            }
        });
    }
}
