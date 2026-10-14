<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Enums\WineType;

/**
 * EU maximum total SO₂ (mg/L) by wine type and sweetness, as checked by the
 * prototype's compliance screen: red 150 (200 above 5 g/L residual sugar),
 * white / rosé / orange / dessert 200 (250), sparkling 185.
 */
class WineCompliance
{
    public const SWEET_ABOVE_G_L = 5.0;

    public function so2Limit(?WineType $type, ?float $residualSugar): int
    {
        $sweet = ($residualSugar ?? 0.0) > self::SWEET_ABOVE_G_L;

        return match ($type) {
            WineType::Red => $sweet ? 200 : 150,
            WineType::Sparkling => 185,
            default => $sweet ? 250 : 200,
        };
    }

    /**
     * @return array{limit: int, compliant: bool, margin: float}
     */
    public function checkSo2(float $totalSo2, ?WineType $type, ?float $residualSugar): array
    {
        $limit = $this->so2Limit($type, $residualSugar);

        return [
            'limit' => $limit,
            'compliant' => $totalSo2 <= $limit,
            'margin' => round($limit - $totalSo2, 1),
        ];
    }
}
