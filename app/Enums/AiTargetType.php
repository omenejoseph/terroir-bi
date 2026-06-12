<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of real record an AI import line commits into. Used by
 * App\Actions\Ai\CommitAiImportLineAction to route the (possibly edited)
 * payload to the matching domain create-action.
 */
enum AiTargetType: string
{
    case Cost = 'cost';
    case Inflow = 'inflow';
    case Order = 'order';
    case InventoryItem = 'inventory_item';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Cost => 'Cost',
            self::Inflow => 'Money in',
            self::Order => 'Order',
            self::InventoryItem => 'Inventory item',
            self::Supplier => 'Supplier',
        };
    }
}
