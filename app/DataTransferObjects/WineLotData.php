<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

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
    public function __construct(
        public readonly WineLot $lot,
        public readonly bool $withDetail = false,
    ) {}

    public static function fromModel(WineLot $lot, bool $withDetail = false): self
    {
        return new self($lot, $withDetail);
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
            'grape_cost' => $lot->grape_cost?->jsonSerialize(),
            'grape_price_per_kg' => $lot->grape_price_per_kg?->jsonSerialize(),
            'harvest_weight_kg' => $lot->harvest_weight_kg !== null ? (string) $lot->harvest_weight_kg : null,
            'notes' => $lot->notes,
        ];

        if ($this->withDetail) {
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
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
