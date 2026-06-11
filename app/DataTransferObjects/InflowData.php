<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Inflow;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class InflowData implements Arrayable, JsonSerializable
{
    public function __construct(public readonly Inflow $inflow) {}

    public static function fromModel(Inflow $inflow): self
    {
        return new self($inflow);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $inflow = $this->inflow;

        return [
            'id' => $inflow->getKey(),
            'customer_id' => $inflow->customer_id,
            'order_id' => $inflow->order_id,
            'order_number' => $inflow->relationLoaded('order') ? $inflow->order?->order_number : null,
            'changes_count' => $inflow->getAttribute('changes_count') !== null ? (int) $inflow->getAttribute('changes_count') : null,
            'date' => $inflow->date->toIso8601String(),
            'amount' => $inflow->amount->jsonSerialize(),
            'status' => $inflow->status->value,
            'is_credit_note' => $inflow->is_credit_note,
            'category' => $inflow->category,
            'reference' => $inflow->reference,
            'payment_method' => $inflow->payment_method?->value,
            'notes' => $inflow->notes,
            'due_date' => $inflow->due_date?->toIso8601String(),
            'received_at' => $inflow->received_at?->toIso8601String(),
            'created_at' => $inflow->created_at?->toIso8601String(),
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
