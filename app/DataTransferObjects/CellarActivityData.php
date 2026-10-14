<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\CellarActivity;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A planned cellar activity as the Today / Plan screens and the API see it.
 * Expects wineLot, vessel, round, assignedTo and executedBy to be eager loaded.
 *
 * @implements Arrayable<string, mixed>
 */
final class CellarActivityData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly CellarActivity $activity,
        public readonly ?string $today = null,
    ) {}

    public static function fromModel(CellarActivity $activity, ?string $today = null): self
    {
        return new self($activity, $today);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $a = $this->activity;
        $plannedFor = $a->planned_for->toDateString();

        return [
            'id' => $a->getKey(),
            'type' => $a->type->value,
            'status' => $a->status->value,
            'payload' => $a->payload,
            'planned_for' => $plannedFor,
            'overdue' => $this->today !== null && $a->isPlanned() && $plannedFor < $this->today,
            'sort_order' => $a->sort_order,
            'note' => $a->note,
            'skip_reason' => $a->skip_reason,
            'deviated' => $a->deviated,
            'applied_at' => $a->applied_at?->toIso8601String(),
            'skipped_at' => $a->skipped_at?->toIso8601String(),
            'wine_lot' => $a->wineLot !== null ? [
                'id' => $a->wineLot->getKey(),
                'lot_number' => $a->wineLot->lot_number,
                'name' => $a->wineLot->name,
                'vintage' => $a->wineLot->vintage,
                'grape_variety' => $a->wineLot->grape_variety,
            ] : null,
            'vessel' => $a->vessel !== null ? [
                'id' => $a->vessel->getKey(),
                'name' => $a->vessel->name,
                'room' => $a->vessel->room,
                'current_volume' => (string) $a->vessel->current_volume,
            ] : null,
            'round' => $a->round !== null ? ['id' => $a->round->getKey(), 'name' => $a->round->name] : null,
            'assigned_to' => $a->assignedTo !== null ? ['id' => $a->assignedTo->getKey(), 'name' => $a->assignedTo->fullName()] : null,
            'executed_by' => $a->executedBy !== null ? ['id' => $a->executedBy->getKey(), 'name' => $a->executedBy->fullName()] : null,
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
