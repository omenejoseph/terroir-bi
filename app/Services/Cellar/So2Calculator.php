<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Enums\VesselType;
use App\Enums\WineType;
use App\Models\EnologicalProduct;
use Illuminate\Validation\ValidationException;

/**
 * Pure SO₂ maths for the cellar, ported from the prototype's
 * enological-calculators.ts and SO₂ screen so the numbers match:
 *
 *  - dose by uplift: (target − current) × L / upliftPerUnit, in the product's
 *    own unit (uplift = mg/L of free SO₂ per 1 unit per 1 L of wine);
 *  - K₂S₂O₅ fallback: (target − current) × L / 1000 × 1.5 grams;
 *  - molecular SO₂: free / (1 + 10^(pH − 1.81)).
 */
class So2Calculator
{
    /** Free SO₂ status buckets (mg/L), as on the prototype's SO₂ screen. */
    public const CRITICAL_BELOW = 15.0;

    public const LOW_BELOW = 25.0;

    public const HIGH_ABOVE = 45.0;

    /** pH assumed when a lot has no pH reading (prototype default). */
    public const DEFAULT_PH = 3.5;

    /**
     * Dose in the product's own unit, rounded to 2 dp (0 when nothing is needed).
     */
    public function doseByUplift(float $targetMgL, float $currentMgL, float $volumeLiters, float $upliftPerUnit): float
    {
        $deficit = max(0.0, $targetMgL - $currentMgL);
        if ($deficit <= 0 || $upliftPerUnit <= 0 || $volumeLiters <= 0) {
            return 0.0;
        }

        return round($deficit * $volumeLiters / $upliftPerUnit, 2);
    }

    /** Grams of potassium metabisulphite, rounded to 1 dp. */
    public function gramsK2S2O5(float $targetMgL, float $currentMgL, float $volumeLiters): float
    {
        $deficit = max(0.0, $targetMgL - $currentMgL);
        if ($deficit <= 0 || $volumeLiters <= 0) {
            return 0.0;
        }

        return round($deficit * $volumeLiters / 1000 * 1.5, 1);
    }

    /**
     * The dose of a specific product. Uses its SO₂ uplift coefficient when set;
     * otherwise treats it as K₂S₂O₅ — which is only meaningful in g or kg, so any
     * other unit without a coefficient is refused (the prototype silently put
     * grams into ml/L products).
     *
     * @return array{quantity: float, unit: string, method: 'uplift'|'k2s2o5'}
     */
    public function doseForProduct(EnologicalProduct $product, float $targetMgL, float $currentMgL, float $volumeLiters): array
    {
        $uplift = $product->so2_uplift_per_unit !== null ? (float) $product->so2_uplift_per_unit : 0.0;

        if ($uplift > 0) {
            return [
                'quantity' => $this->doseByUplift($targetMgL, $currentMgL, $volumeLiters, $uplift),
                'unit' => $product->unit,
                'method' => 'uplift',
            ];
        }

        $unit = mb_strtolower(trim($product->unit));
        if (! in_array($unit, ['g', 'kg'], true)) {
            throw ValidationException::withMessages([
                'enological_product_id' => "Set the SO₂ uplift for {$product->name} — its dose in {$product->unit} can't be worked out otherwise.",
            ]);
        }

        $grams = $this->gramsK2S2O5($targetMgL, $currentMgL, $volumeLiters);

        return [
            'quantity' => $unit === 'kg' ? round($grams / 1000, 3) : $grams,
            'unit' => $product->unit,
            'method' => 'k2s2o5',
        ];
    }

    /** Molecular SO₂ (mg/L), rounded to 2 dp. */
    public function molecular(float $freeMgL, float $ph): float
    {
        return round($freeMgL / (1 + 10 ** ($ph - 1.81)), 2);
    }

    /**
     * The free-SO₂ level the prototype showed as a reference per wine type —
     * a guide, never set automatically. Oak vessels get +3 mg/L.
     */
    public function referenceTarget(?WineType $type, bool $oak): int
    {
        $base = match ($type) {
            WineType::Red => 28,
            WineType::White => 35,
            WineType::Rose => 32,
            WineType::Orange => 25,
            default => 30,
        };

        return $oak ? $base + 3 : $base;
    }

    /** @return 'critical'|'low'|'ok'|'high'|'no_data' */
    public function status(?float $freeMgL): string
    {
        return match (true) {
            $freeMgL === null => 'no_data',
            $freeMgL < self::CRITICAL_BELOW => 'critical',
            $freeMgL < self::LOW_BELOW => 'low',
            $freeMgL <= self::HIGH_ABOVE => 'ok',
            default => 'high',
        };
    }

    public function isOak(?string $material, ?VesselType $type): bool
    {
        if ($type === VesselType::Barrel || $type === VesselType::Barrique) {
            return true;
        }

        $m = mb_strtolower((string) $material);

        foreach (['oak', 'hrast', 'barrique', 'wood'] as $needle) {
            if ($m !== '' && str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }
}
