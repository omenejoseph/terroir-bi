<?php

declare(strict_types=1);

namespace App\Http\Requests\Kitchen;

use App\Enums\KitchenWorkOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeKitchenWorkOrderStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(KitchenWorkOrderStatus::class)],
        ];
    }
}
