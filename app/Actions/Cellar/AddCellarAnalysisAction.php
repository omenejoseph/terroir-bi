<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarAnalysis;
use App\Models\WineLot;

/** Record a lab analysis for a lot (optionally vessel-scoped). */
class AddCellarAnalysisAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(WineLot $lot, array $data, string $createdById): CellarAnalysis
    {
        $payload = [
            'vessel_id' => $data['vessel_id'] ?? null,
            'created_by_id' => $createdById,
            'date' => $data['date'] ?? now(),
            'note' => $data['note'] ?? null,
        ];

        foreach (CellarAnalysis::PARAMETERS as $param) {
            $payload[$param] = $data[$param] ?? null;
        }

        /** @var CellarAnalysis $analysis */
        $analysis = $lot->analyses()->create($payload);

        return $analysis;
    }
}
