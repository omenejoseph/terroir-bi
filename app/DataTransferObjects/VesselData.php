<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Vessel;
use App\Models\VesselLot;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class VesselData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly Vessel $vessel,
        public readonly bool $withLots = false,
    ) {}

    public static function fromModel(Vessel $vessel, bool $withLots = false): self
    {
        return new self($vessel, $withLots);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $vessel = $this->vessel;

        $payload = [
            'id' => $vessel->getKey(),
            'name' => $vessel->name,
            'type' => $vessel->type->value,
            'material' => $vessel->material,
            'capacity_liters' => (string) $vessel->capacity_liters,
            'current_volume' => (string) $vessel->current_volume,
            'location' => $vessel->location,
            'status' => $vessel->status->value,
            'is_active' => $vessel->is_active,
            'is_faulty' => $vessel->is_faulty,
            'fault_note' => $vessel->fault_note,
            'room' => $vessel->room,
            'position_x' => $vessel->position_x,
            'position_y' => $vessel->position_y,
            'map_width' => $vessel->map_width,
            'map_height' => $vessel->map_height,
            'rotation' => $vessel->rotation,
            'notes' => $vessel->notes,
        ];

        if ($this->withLots) {
            $payload['lots'] = $vessel->vesselLots
                ->map(fn (VesselLot $vl): array => [
                    'vessel_lot_id' => $vl->getKey(),
                    'wine_lot_id' => $vl->wine_lot_id,
                    'volume' => (string) $vl->volume,
                    'lot' => $vl->relationLoaded('wineLot') && $vl->wineLot !== null ? [
                        'id' => $vl->wineLot->getKey(),
                        'lot_number' => $vl->wineLot->lot_number,
                        'name' => $vl->wineLot->name,
                        'grape_variety' => $vl->wineLot->grape_variety,
                        'vintage' => $vl->wineLot->vintage,
                        'wine_type' => $vl->wineLot->wine_type?->value,
                    ] : null,
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
