<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\InventoryItem;
use App\Models\RecipeItem;
use App\Services\Inventory\RecipeCost;
use App\Support\Measure;
use App\Support\Money\Money;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One input line of an item's recipe, with the input item's display fields
 * resolved for the client — and what the line costs.
 *
 * @implements Arrayable<string, mixed>
 */
final class RecipeLineData implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $unitOptions
     * @param  ?array{minor: float, currency: string}  $unitCost  one of the line's own unit, e.g. per ml
     * @param  ?array{minor: int, currency: string, formatted: string}  $lineCost
     */
    public function __construct(
        public readonly ?string $inputId,
        public readonly string $inputName,
        public readonly string $inputSku,
        public readonly string $inputUnit,
        public readonly string $quantity,
        public readonly ?string $inputGroup,
        public readonly ?string $inputStock,
        public readonly string $kind = 'catalog',
        public readonly ?string $wineLotId = null,
        public readonly ?string $unit = null,
        public readonly array $unitOptions = [],
        public readonly ?array $unitCost = null,
        public readonly ?array $lineCost = null,
        public readonly ?int $customCost = null,
    ) {}

    /**
     * @param  ?array{kind: string, unit: ?string, base_quantity: float, unit_cost: ?array{minor: float, currency: string}, line_cost: ?Money}  $cost
     */
    public static function fromModel(RecipeItem $line, ?array $cost = null): self
    {
        $input = $line->input;
        $isItem = $input instanceof InventoryItem;
        $kind = $line->kind();

        // Custom (non-catalog) lines fall back to their own name/unit.
        $baseUnit = $isItem ? $input->unit : ($kind === 'cellar' ? 'l' : $line->custom_unit);

        return new self(
            inputId: $line->input_id,
            inputName: $isItem ? $input->name : ($line->custom_name ?? ''),
            inputSku: $isItem ? $input->sku : '',
            inputUnit: $isItem ? $input->unit : ($line->custom_unit ?? ''),
            quantity: (string) $line->quantity,
            inputGroup: $isItem ? $input->group : null,
            inputStock: $isItem ? (string) $input->current_stock : null,
            kind: $kind,
            wineLotId: $line->wine_lot_id,
            unit: $line->unit,
            unitOptions: Measure::options($baseUnit),
            unitCost: $cost['unit_cost'] ?? null,
            lineCost: isset($cost['line_cost']) ? $cost['line_cost']->jsonSerialize() : null,
            customCost: $kind === 'custom' ? $line->custom_cost?->getMinorAmount() : null,
        );
    }

    /**
     * A whole recipe, costed in one pass.
     *
     * @param  iterable<RecipeItem>  $recipe
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $recipe, RecipeCost $cost): array
    {
        $recipe = collect($recipe)->values();
        $recipe->each->loadMissing(['input', 'wineLot']);
        $costs = $cost->lines($recipe);

        return array_values($recipe->map(fn (RecipeItem $line, int $at): array => self::fromModel($line, $costs[$at])->toArray())->all());
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
            'kind' => $this->kind,
            'wine_lot_id' => $this->wineLotId,
            'unit' => $this->unit,
            'unit_options' => $this->unitOptions,
            'unit_cost' => $this->unitCost,
            'line_cost' => $this->lineCost,
            'custom_cost' => $this->customCost,
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
