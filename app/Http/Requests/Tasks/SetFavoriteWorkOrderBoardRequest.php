<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetFavoriteWorkOrderBoardRequest extends FormRequest
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
            // null clears the favourite.
            'board_id' => ['nullable', 'string', Rule::exists('work_order_boards', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
