<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Broadcast;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'tenants' => ['array'],
            'tenants.*' => Rule::exists((new Tenant)->getTable(), 'id'),
        ];
    }
}
