<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Bottling;
use App\Models\CellarActivity;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Models\WineLotGrape;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class WineLotData implements Arrayable, JsonSerializable
{
    /**
     * @param  array{total: int, additions: int, grape: int, linked: int, carried: int, per_liter: int, per_bottle_750: int}|null  $costBreakdown
     */
    public function __construct(
        public readonly WineLot $lot,
        public readonly bool $withDetail = false,
        public readonly ?array $costBreakdown = null,
    ) {}

    /**
     * @param  array{total: int, additions: int, grape: int, linked: int, carried: int, per_liter: int, per_bottle_750: int}|null  $costBreakdown
     */
    public static function fromModel(WineLot $lot, bool $withDetail = false, ?array $costBreakdown = null): self
    {
        return new self($lot, $withDetail, $costBreakdown);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $lot = $this->lot;

        $payload = [
            'id' => $lot->getKey(),
            'lot_number' => $lot->lot_number,
            'name' => $lot->name,
            'grape_variety' => $lot->grape_variety,
            'vintage' => $lot->vintage,
            'vineyard' => $lot->vineyard,
            'wine_type' => $lot->wine_type?->value,
            'initial_volume' => (string) $lot->initial_volume,
            'current_volume' => (string) $lot->current_volume,
            'status' => $lot->status->value,
            'fermentation_template_id' => $lot->fermentation_template_id,
            'grape_cost' => $lot->grape_cost?->jsonSerialize(),
            'grape_price_per_kg' => $lot->grape_price_per_kg?->jsonSerialize(),
            'harvest_weight_kg' => $lot->harvest_weight_kg !== null ? (string) $lot->harvest_weight_kg : null,
            'notes' => $lot->notes,
            'latest_analysis' => $this->latestAnalysis(),
            'latest_addition' => $this->latestAddition(),
            'carried_cost' => $lot->carried_cost,
        ];

        // Bottled archive (ListWineLotsQuery with_counts).
        if ($lot->relationLoaded('latestBottling')) {
            $b = $lot->latestBottling;
            $payload['counts'] = [
                'additions' => (int) $lot->getAttribute('additions_count'),
                'analyses' => (int) $lot->getAttribute('analyses_count'),
                'bottlings' => (int) $lot->getAttribute('bottlings_count'),
            ];
            $payload['latest_bottling'] = $b !== null ? [
                'date' => $b->date->toIso8601String(),
                'bottle_count' => $b->bottle_count,
                'bottle_volume_ml' => $b->bottle_volume_ml,
                'inventory_item' => $b->inventoryItem !== null ? ['id' => $b->inventoryItem->getKey(), 'name' => $b->inventoryItem->name] : null,
            ] : null;
        }

        if (! $this->withDetail) {
            return $payload;
        }

        $payload['cost_breakdown'] = $this->costBreakdown;
        $payload['grapes'] = $lot->grapes
            ->map(fn (WineLotGrape $g): array => [
                'id' => $g->getKey(),
                'grape_variety' => $g->grape_variety,
                'percentage' => $g->percentage !== null ? (string) $g->percentage : null,
                'price_per_kg' => $g->price_per_kg?->jsonSerialize(),
                'weight_kg' => $g->weight_kg !== null ? (string) $g->weight_kg : null,
            ])->all();

        $payload['vessels'] = $lot->vesselLots
            ->map(fn (VesselLot $vl): array => [
                'vessel_lot_id' => $vl->getKey(),
                'vessel_id' => $vl->vessel_id,
                'vessel_name' => $vl->relationLoaded('vessel') && $vl->vessel !== null ? $vl->vessel->name : null,
                'volume' => (string) $vl->volume,
            ])->all();

        $payload['analyses'] = self::newestFirst($lot->analyses, 'date')
            ->map(fn (CellarAnalysis $a): array => $this->analysis($a))->all();

        $payload['additions'] = self::newestFirst($lot->additions, 'created_at')
            ->map(fn (CellarAddition $a): array => [
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
            ])->all();

        $payload['processes'] = self::newestFirst($lot->processes, 'date')
            ->map(fn (CellarProcess $p): array => [
                'id' => $p->getKey(),
                'kind' => $p->kind,
                'vessel_id' => $p->vessel_id,
                'vessel_name' => $p->relationLoaded('vessel') ? $p->vessel?->name : null,
                'volume' => $p->volume !== null ? (string) $p->volume : null,
                'note' => $p->note,
                'date' => $p->date->toIso8601String(),
            ])->all();

        $payload['tasting_notes'] = self::newestFirst($lot->tastingNotes, 'date')
            ->map(fn (CellarTastingNote $n): array => [
                'id' => $n->getKey(),
                'vessel_id' => $n->vessel_id,
                'date' => $n->date->toIso8601String(),
                'appearance' => $n->appearance,
                'nose' => $n->nose,
                'palate' => $n->palate,
                'overall' => $n->overall,
                'score' => $n->score,
                'note' => $n->note,
            ])->all();

        $payload['transfers'] = $lot->transfersOut->merge($lot->transfersIn)
            ->sortByDesc('created_at')->values()
            ->map(fn (CellarTransfer $t): array => [
                'id' => $t->getKey(),
                'type' => $t->type->value,
                'direction' => $t->from_lot_id === $lot->getKey() ? 'out' : 'in',
                'from_lot_id' => $t->from_lot_id,
                'to_lot_id' => $t->to_lot_id,
                'from_lot_name' => $t->relationLoaded('fromLot') ? $t->fromLot?->name : null,
                'to_lot_name' => $t->relationLoaded('toLot') ? $t->toLot?->name : null,
                'from_vessel_name' => $t->relationLoaded('fromVessel') ? $t->fromVessel?->name : null,
                'to_vessel_name' => $t->relationLoaded('toVessel') ? $t->toVessel?->name : null,
                'volume_liters' => (string) $t->volume_liters,
                'note' => $t->note,
                'date' => $t->created_at?->toIso8601String(),
            ])->all();

        $payload['planned_additions'] = $lot->relationLoaded('plannedAdditions')
            ? $lot->plannedAdditions->map(fn (CellarActivity $a): array => [
                'id' => $a->getKey(),
                'planned_for' => $a->planned_for->toDateString(),
                'product_name' => $a->payload['product_name'] ?? null,
                'quantity' => isset($a->payload['quantity']) ? (string) $a->payload['quantity'] : null,
                'unit' => $a->payload['unit'] ?? null,
                'vessel' => $a->vessel !== null ? ['id' => $a->vessel->getKey(), 'name' => $a->vessel->name] : null,
                'round_id' => $a->round_id,
            ])->all()
            : [];

        $payload['bottlings'] = self::newestFirst($lot->bottlings, 'date')
            ->map(fn (Bottling $b): array => [
                'id' => $b->getKey(),
                'bottle_count' => $b->bottle_count,
                'bottle_volume_ml' => $b->bottle_volume_ml,
                'volume_used' => (string) $b->volume_used,
                'inventory_item_id' => $b->inventory_item_id,
                'inventory_item_name' => $b->relationLoaded('inventoryItem') ? $b->inventoryItem?->name : null,
                'note' => $b->note,
                'date' => $b->date->toIso8601String(),
            ])->all();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function analysis(CellarAnalysis $a): array
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

    /**
     * Compact latest-analysis summary for list views (null unless eager-loaded).
     *
     * @return array<string, mixed>|null
     */
    private function latestAnalysis(): ?array
    {
        if (! $this->lot->relationLoaded('latestAnalysis') || $this->lot->latestAnalysis === null) {
            return null;
        }
        $a = $this->lot->latestAnalysis;

        return [
            'date' => $a->date->toIso8601String(),
            'ph' => $a->ph !== null ? (string) $a->ph : null,
            'alcohol' => $a->alcohol !== null ? (string) $a->alcohol : null,
            'free_so2' => $a->free_so2 !== null ? (string) $a->free_so2 : null,
            'total_so2' => $a->total_so2 !== null ? (string) $a->total_so2 : null,
        ];
    }

    /**
     * Compact latest-addition summary for list views (null unless eager-loaded).
     *
     * @return array<string, mixed>|null
     */
    private function latestAddition(): ?array
    {
        if (! $this->lot->relationLoaded('latestAddition') || $this->lot->latestAddition === null) {
            return null;
        }
        $a = $this->lot->latestAddition;

        return [
            'name' => $a->name,
            'quantity' => (string) $a->quantity,
            'unit' => $a->unit,
            'date' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Relations load in no particular order; every list here is newest first (by its own date,
     * then by when it was recorded), so "latest" is always element 0 for the API and the screens.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $rows
     * @return Collection<int, TModel>
     */
    private static function newestFirst(Collection $rows, string $column): Collection
    {
        return $rows->sortBy([[$column, 'desc'], ['created_at', 'desc']])->values();
    }
}
