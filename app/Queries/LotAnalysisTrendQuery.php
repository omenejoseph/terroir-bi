<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\CellarAnalysis;
use App\Models\WineLot;

/**
 * Time-series of a lot's analyses for charting — every reading, oldest first,
 * with its vessel and all measurement parameters as nullable strings.
 */
class LotAnalysisTrendQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function get(WineLot $lot): array
    {
        $trend = $lot->analyses()
            ->with('vessel:id,name')
            ->orderBy('date')
            ->get()
            ->map(function (CellarAnalysis $a): array {
                $row = [
                    'id' => $a->getKey(),
                    'date' => $a->date->toIso8601String(),
                    'vessel_id' => $a->vessel_id,
                    'vessel_name' => $a->vessel?->name,
                ];
                foreach (CellarAnalysis::PARAMETERS as $param) {
                    $value = $a->getAttribute($param);
                    $row[$param] = $value !== null ? (float) $value : null;
                }

                return $row;
            })
            ->all();

        return array_values($trend);
    }
}
