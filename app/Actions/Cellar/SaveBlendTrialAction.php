<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\BlendTrial;

/** Keep a blend trial from the workbench (lots, ratios, size, destination and its projection). */
class SaveBlendTrialAction
{
    /**
     * @param  array{name: string, components: list<array{wine_lot_id: string, ratio: float|int|string}>, target_volume?: float|int|string|null, destination_vessel_id?: string|null, projection?: array<string, mixed>|null}  $data
     */
    public function execute(array $data, string $userId): BlendTrial
    {
        return BlendTrial::create([
            'name' => trim($data['name']),
            'components' => array_map(fn (array $c): array => ['wine_lot_id' => (string) $c['wine_lot_id'], 'ratio' => (float) $c['ratio']], $data['components']),
            'target_volume' => isset($data['target_volume']) ? (string) $data['target_volume'] : null,
            'destination_vessel_id' => $data['destination_vessel_id'] ?? null,
            'projection' => $data['projection'] ?? null,
            'created_by_id' => $userId,
        ]);
    }
}
