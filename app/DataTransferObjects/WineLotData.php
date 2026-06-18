<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Bottling;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Models\WineLotGrape;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class WineLotData implements Arrayable, JsonSerializable
{
    /**
     * @param  array{total: int, additions: int, grape: int, per_liter: int, per_bottle_750: int}|null  $costBreakdown
     */
    public function __construct(
        public readonly WineLot $lot,
        public readonly bool $withDetail = false,
        public readonly ?array $costBreakdown = null,
    ) {}

    /**
     * @param  array{total: int, additions: int, grape: int, per_liter: int, per_bottle_750: int}|null  $costBreakdown
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
        ];

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

        $payload['analyses'] = $lot->analyses
            ->map(fn (CellarAnalysis $a): array => $this->analysis($a))->all();

        $payload['additions'] = $lot->additions
            ->map(fn (CellarAddition $a): array => [
                'id' => $a->getKey(),
                'name' => $a->name,
                'category' => $a->category,
                'quantity' => (string) $a->quantity,
                'unit' => $a->unit,
                'total_cost' => $a->total_cost?->jsonSerialize(),
                'note' => $a->note,
                'date' => $a->created_at?->toIso8601String(),
            ])->all();

        $payload['processes'] = $lot->processes
            ->map(fn (CellarProcess $p): array => [
                'id' => $p->getKey(),
                'kind' => $p->kind,
                'vessel_id' => $p->vessel_id,
                'volume' => $p->volume !== null ? (string) $p->volume : null,
                'note' => $p->note,
                'date' => $p->date->toIso8601String(),
            ])->all();

        $payload['tasting_notes'] = $lot->tastingNotes
            ->map(fn (CellarTastingNote $n): array => [
                'id' => $n->getKey(),
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
                'volume_liters' => (string) $t->volume_liters,
                'note' => $t->note,
                'date' => $t->created_at?->toIso8601String(),
            ])->all();

        $payload['bottlings'] = $lot->bottlings
            ->map(fn (Bottling $b): array => [
                'id' => $b->getKey(),
                'bottle_count' => $b->bottle_count,
                'bottle_volume_ml' => $b->bottle_volume_ml,
                'volume_used' => (string) $b->volume_used,
                'inventory_item_id' => $b->inventory_item_id,
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
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
