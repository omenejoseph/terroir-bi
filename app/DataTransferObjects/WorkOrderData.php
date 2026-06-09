<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class WorkOrderData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly WorkOrder $task) {}

    public static function fromModel(WorkOrder $task): self
    {
        return new self($task);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $t = $this->task;
        $assignee = $t->assignee;

        return [
            'id' => $t->getKey(),
            'title' => $t->title,
            'description' => $t->description,
            'category' => $t->category,
            'priority' => $t->priority->value,
            'status' => $t->status->value,
            'start_date' => $t->start_date?->toIso8601String(),
            'due_date' => $t->due_date?->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'sort_order' => $t->sort_order,
            'assignee' => $assignee instanceof User
                ? ['id' => $assignee->getKey(), 'name' => trim($assignee->first_name.' '.$assignee->last_name)]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
