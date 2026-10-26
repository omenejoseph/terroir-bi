<?php

declare(strict_types=1);

namespace App\Http\Requests\Kitchen;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A prep ticket and its lines; each line names a kitchen item or carries free text. */
class KitchenWorkOrderRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();

        return [
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'assigned_to' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['present', 'array', 'max:200'],
            'items.*.item_id' => ['nullable', 'string', Rule::exists('kitchen_items', 'id')->where('tenant_id', $tenantId)],
            'items.*.custom_name' => ['nullable', 'required_without:items.*.item_id', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.unit' => ['nullable', 'string', 'max:10'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
