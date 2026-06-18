<?php

declare(strict_types=1);

namespace App\Services\Vineyards;

/**
 * Estimate a parcel's crop yield (kg) from a field sample:
 *   yield = clusters × avgClusterWeightG × vineCount / sampleVineCount / 1000.
 */
class CropYieldEstimator
{
    public function estimate(int $clusterCount, float $avgClusterWeightG, int $sampleVineCount, ?int $vineCount): float
    {
        if ($sampleVineCount <= 0 || $vineCount === null || $vineCount <= 0) {
            return 0.0;
        }

        return ($clusterCount * $avgClusterWeightG * $vineCount) / $sampleVineCount / 1000;
    }
}
