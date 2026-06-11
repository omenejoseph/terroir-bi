<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\InventoryCheck;
use App\Models\InventoryCheckLine;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * An inventory check (stocktake) for the audit history. With `withLines`, the
 * adjusted lines (system vs physical vs difference) are included.
 *
 * @implements Arrayable<string, mixed>
 */
final class InventoryCheckData implements Arrayable, JsonSerializable
{
    public function __construct(
        private readonly InventoryCheck $check,
        private readonly bool $withLines = false,
    ) {}

    public static function fromModel(InventoryCheck $check, bool $withLines = false): self
    {
        return new self($check, $withLines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $user = $this->check->performedBy;
        $name = $user !== null ? trim($user->first_name.' '.$user->last_name) : null;

        $out = [
            'id' => $this->check->getKey(),
            'reference' => $this->check->reference,
            'performed_by' => $name !== '' ? $name : null,
            'items_counted' => $this->check->items_counted,
            'items_adjusted' => $this->check->items_adjusted,
            'net_difference' => (string) $this->check->net_difference,
            'created_at' => $this->check->created_at?->toIso8601String(),
        ];

        if ($this->withLines) {
            $out['lines'] = $this->check->lines
                ->map(fn (InventoryCheckLine $line): array => [
                    'name' => $line->name,
                    'sku' => $line->sku,
                    'system_count' => (string) $line->system_count,
                    'physical_count' => (string) $line->physical_count,
                    'difference' => (string) $line->difference,
                ])
                ->all();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
