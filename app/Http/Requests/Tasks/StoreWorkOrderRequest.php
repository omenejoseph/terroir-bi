<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkOrderCategory;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', Rule::enum(WorkOrderCategory::class)],
            // Which named board this lands on — independent of category (what
            // kind of work it is). Defaults to whichever board is selected
            // when the task is created; see WorkOrderBoardPresenter.
            'board_id' => ['sometimes', 'nullable', 'string', Rule::exists('work_order_boards', 'id')->where('tenant_id', $tenantId)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'sort_order' => ['sometimes', 'integer'],
            'assignee_id' => ['sometimes', 'nullable', 'string', Rule::exists('memberships', 'user_id')->where('tenant_id', $tenantId)],
            // Where the work happens. Both columns have always existed on the
            // table; the board's card names the vessel, so it is settable now.
            'vessel_id' => ['sometimes', 'nullable', 'string', Rule::exists('vessels', 'id')->where('tenant_id', $tenantId)],
            'wine_lot_id' => ['sometimes', 'nullable', 'string', Rule::exists('wine_lots', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
