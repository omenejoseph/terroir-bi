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
        public readonly string $inputId,
        public readonly string $inputName,
        public readonly string $inputSku,
        public readonly string $inputUnit,
        public readonly string $quantity,
    ) {}

    public static function fromModel(RecipeItem $line): self
    {
        $input = $line->input;

        return new self(
            inputId: $line->input_id,
            inputName: $input instanceof InventoryItem ? $input->name : '',
            inputSku: $input instanceof InventoryItem ? $input->sku : '',
            inputUnit: $input instanceof InventoryItem ? $input->unit : '',
            quantity: (string) $line->quantity,
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
