<?php

declare(strict_types=1);

namespace App\Http\Requests\Hospitality;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An agency's price for one program, given either as a flat per-head price or as a rebate off the
 * program's base price. A rebate is stored alongside the price it produces, so the price follows
 * the base price when that changes (UpdateProgramAction).
 */
class SetAgencyPriceRequest extends FormRequest
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
            'price_per_pax' => ['required_without:rebate_percent', 'nullable', 'integer', 'min:0'], // money: minor units
            'rebate_percent' => ['required_without:price_per_pax', 'nullable', 'numeric', 'gt:0', 'max:100'],
        ];
    }
}
