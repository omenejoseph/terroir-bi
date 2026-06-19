<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One stock ledger entry, summarised for the client.
 *
 * @implements Arrayable<string, mixed>
 */
final class StockMovementData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $quantity,
        public readonly ?string $unit,
        public readonly ?string $reference,
        public readonly ?string $note,
        public readonly bool $isReconciliation,
        public readonly ?string $createdAt,
        /** @var array{id: string, name: string}|null */
        public readonly ?array $createdBy,
    ) {}

    public static function fromModel(StockMovement $movement): self
    {
        return new self(
            id: $movement->getKey(),
            type: $movement->type->value,
            quantity: (string) $movement->quantity,
            unit: $movement->unit,
            reference: $movement->reference,
            note: $movement->note,
            isReconciliation: $movement->is_reconciliation,
            createdAt: $movement->created_at?->toIso8601String(),
            createdBy: self::user($movement->createdBy),
        );
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => $user->getKey(), 'name' => trim($user->first_name.' '.$user->last_name)];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'reference' => $this->reference,
            'note' => $this->note,
            'is_reconciliation' => $this->isReconciliation,
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy,
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
