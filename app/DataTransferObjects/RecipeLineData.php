<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\InventoryItem;
use App\Models\RecipeItem;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One input line of an item's recipe, with the input item's display fields
 * resolved for the client.
 *
 * @implements Arrayable<string, mixed>
 */
final class RecipeLineData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly ?string $inputId,
        public readonly string $inputName,
        public readonly string $inputSku,
        public readonly string $inputUnit,
        public readonly string $quantity,
        public readonly ?string $inputGroup,
        public readonly ?string $inputStock,
    ) {}

    public static function fromModel(RecipeItem $line): self
    {
        $input = $line->input;
        $isItem = $input instanceof InventoryItem;

        // Custom (non-catalog) lines fall back to their own name/unit.
        return new self(
            inputId: $line->input_id,
            inputName: $isItem ? $input->name : ($line->custom_name ?? ''),
            inputSku: $isItem ? $input->sku : '',
            inputUnit: $isItem ? $input->unit : ($line->custom_unit ?? ''),
            quantity: (string) $line->quantity,
            inputGroup: $isItem ? $input->group : null,
            inputStock: $isItem ? (string) $input->current_stock : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'input_id' => $this->inputId,
            'input_name' => $this->inputName,
            'input_sku' => $this->inputSku,
            'input_unit' => $this->inputUnit,
            'quantity' => $this->quantity,
            'input_group' => $this->inputGroup,
            'input_stock' => $this->inputStock,
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
