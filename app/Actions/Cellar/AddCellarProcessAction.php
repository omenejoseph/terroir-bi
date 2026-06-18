<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarProcess;
use App\Models\WineLot;

/** Record a cellar operation (racking, filtration, …) on a lot. */
class AddCellarProcessAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(WineLot $lot, array $data, string $createdById): CellarProcess
    {
        /** @var CellarProcess $process */
        $process = $lot->processes()->create([
            'vessel_id' => $data['vessel_id'] ?? null,
            'created_by_id' => $createdById,
            'date' => $data['date'] ?? now(),
            'kind' => $data['kind'],
            'volume' => $data['volume'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return $process;
    }
}
