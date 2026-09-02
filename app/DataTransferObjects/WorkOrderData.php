<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\User;
use App\Models\Vessel;
use App\Models\WineLot;
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
        $vessel = $t->vessel;
        $lot = $t->wineLot;

        return [
            'id' => $t->getKey(),
            'title' => $t->title,
            'description' => $t->description,
            'category' => $t->category?->value,
            'priority' => $t->priority->value,
            'status' => $t->status->value,
            'start_date' => $t->start_date?->toIso8601String(),
            'due_date' => $t->due_date?->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'sort_order' => $t->sort_order,
            'assignee' => $assignee instanceof User
                ? ['id' => $assignee->getKey(), 'name' => trim($assignee->first_name.' '.$assignee->last_name)]
                : null,
            // Where the work happens, as the board's card reports it.
            'vessel' => $vessel instanceof Vessel
                ? ['id' => $vessel->getKey(), 'name' => $vessel->name]
                : null,
            'wine_lot' => $lot instanceof WineLot
                ? ['id' => $lot->getKey(), 'name' => $lot->name]
                : null,
            // Provenance, for the task drawer's Information panel (324:862).
            'created_by' => $t->createdBy instanceof User
                ? ['id' => $t->createdBy->getKey(), 'name' => trim($t->createdBy->first_name.' '.$t->createdBy->last_name)]
                : null,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
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
