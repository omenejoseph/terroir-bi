<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PlatformAdmins;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromotePlatformAdminRequest extends FormRequest
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
            'user_id' => [
                'required',
                Rule::exists((new User)->getTable(), 'id')->where('is_platform_admin', false),
            ],
        ];
    }
}
