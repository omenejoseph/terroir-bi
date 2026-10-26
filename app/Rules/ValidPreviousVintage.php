<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\InventoryItem;
use App\Services\Inventory\InventoryVintageChains;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The previous vintage an item names must be another real item that is not already followed by a
 * vintage of its own, and must not loop the chain back to the item being saved.
 */
class ValidPreviousVintage implements ValidationRule
{
    public function __construct(private readonly ?InventoryItem $item = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $previous = InventoryItem::query()->whereKey($value)->first();

        if ($previous === null) {
            $fail(__('That product does not exist.'));

            return;
        }

        if ($this->item !== null && $previous->getKey() === $this->item->getKey()) {
            $fail(__('A product cannot be its own previous vintage.'));

            return;
        }

        $followedBy = InventoryItem::query()->where('previous_vintage_id', $previous->getKey())->value('id');

        if ($followedBy !== null && ($this->item === null || $followedBy !== $this->item->getKey())) {
            $fail(__('That vintage is already followed by another one.'));

            return;
        }

        if ($this->item !== null && app(InventoryVintageChains::class)->wouldLoop((string) $this->item->getKey(), (string) $previous->getKey())) {
            $fail(__('That would make the vintages loop back on themselves.'));
        }
    }
}
