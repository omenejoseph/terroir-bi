<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Bottling;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;

/**
 * One row of each of a wine lot's child tables, as the lot page and the API both send it. Shared so
 * the full lot payload and the page's paginated tables can never describe a record differently.
 */
final class LotRows
{
    /** @return array<string, mixed> */
    public static function analysis(CellarAnalysis $a): array
    {
        $row = [
            'id' => $a->getKey(),
            'vessel_id' => $a->vessel_id,
            'vessel_name' => $a->relationLoaded('vessel') ? $a->vessel?->name : null,
            'date' => $a->date->toIso8601String(),
            'note' => $a->note,
        ];
        foreach (CellarAnalysis::PARAMETERS as $param) {
            $value = $a->getAttribute($param);
            $row[$param] = $value !== null ? (string) $value : null;
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public static function addition(CellarAddition $a): array
    {
        return [
            'id' => $a->getKey(),
            'name' => $a->name,
            'category' => $a->category,
            'enological_product_id' => $a->enological_product_id,
            'vessel_id' => $a->vessel_id,
            'vessel' => $a->vessel !== null ? ['id' => $a->vessel->getKey(), 'name' => $a->vessel->name] : null,
            'quantity' => (string) $a->quantity,
            'unit' => $a->unit,
            'total_cost' => $a->total_cost?->jsonSerialize(),
            'note' => $a->note,
            'date' => $a->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function process(CellarProcess $p): array
    {
        return [
            'id' => $p->getKey(),
            'kind' => $p->kind,
            'vessel_id' => $p->vessel_id,
            'vessel_name' => $p->relationLoaded('vessel') ? $p->vessel?->name : null,
            'volume' => $p->volume !== null ? (string) $p->volume : null,
            'note' => $p->note,
            'date' => $p->date->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function tasting(CellarTastingNote $n): array
    {
        return [
            'id' => $n->getKey(),
            'vessel_id' => $n->vessel_id,
            'vessel_name' => $n->relationLoaded('vessel') ? $n->vessel?->name : null,
            'date' => $n->date->toIso8601String(),
            'appearance' => $n->appearance,
            'nose' => $n->nose,
            'palate' => $n->palate,
            'overall' => $n->overall,
            'score' => $n->score,
            'note' => $n->note,
        ];
    }

    /** @return array<string, mixed> */
    public static function transfer(CellarTransfer $t, string $lotId): array
    {
        return [
            'id' => $t->getKey(),
            'type' => $t->type->value,
            'direction' => $t->from_lot_id === $lotId ? 'out' : 'in',
            'from_lot_id' => $t->from_lot_id,
            'to_lot_id' => $t->to_lot_id,
            'from_lot_name' => $t->relationLoaded('fromLot') ? $t->fromLot?->name : null,
            'to_lot_name' => $t->relationLoaded('toLot') ? $t->toLot?->name : null,
            'from_vessel_name' => $t->relationLoaded('fromVessel') ? $t->fromVessel?->name : null,
            'to_vessel_name' => $t->relationLoaded('toVessel') ? $t->toVessel?->name : null,
            'volume_liters' => (string) $t->volume_liters,
            'note' => $t->note,
            'date' => $t->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function bottling(Bottling $b): array
    {
        return [
            'id' => $b->getKey(),
            'bottle_count' => $b->bottle_count,
            'bottle_volume_ml' => $b->bottle_volume_ml,
            'volume_used' => (string) $b->volume_used,
            'inventory_item_id' => $b->inventory_item_id,
            'inventory_item_name' => $b->relationLoaded('inventoryItem') ? $b->inventoryItem?->name : null,
            'note' => $b->note,
            'date' => $b->date->toIso8601String(),
        ];
    }
}
