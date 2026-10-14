<?php

declare(strict_types=1);

namespace Tests\Unit\Cellar;

use App\Enums\VesselType;
use App\Enums\WineType;
use App\Models\EnologicalProduct;
use App\Services\Cellar\So2Calculator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Golden values from the prototype's enological-calculators.ts and SO₂ screen. */
class So2CalculatorTest extends TestCase
{
    private So2Calculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new So2Calculator;
    }

    public function test_dose_by_uplift(): void
    {
        // 10 mg/L deficit × 1000 L ÷ 0.5 mg/L per unit per L
        $this->assertSame(20000.0, $this->calc->doseByUplift(28, 18, 1000, 0.5));
        $this->assertSame(66.67, $this->calc->doseByUplift(30, 10, 500, 150));
        $this->assertSame(0.0, $this->calc->doseByUplift(20, 25, 1000, 0.5), 'already above target');
        $this->assertSame(0.0, $this->calc->doseByUplift(30, 10, 1000, 0));
    }

    public function test_k2s2o5_fallback_grams(): void
    {
        // 10 × 1000 / 1000 × 1.5
        $this->assertSame(15.0, $this->calc->gramsK2S2O5(28, 18, 1000));
        $this->assertSame(5.6, $this->calc->gramsK2S2O5(25, 10, 250)); // 15 × 250 / 1000 × 1.5 = 5.625
        $this->assertSame(0.0, $this->calc->gramsK2S2O5(10, 18, 1000));
    }

    public function test_dose_for_product_picks_the_method_and_unit(): void
    {
        $uplift = new EnologicalProduct(['name' => 'Solution', 'unit' => 'ml', 'so2_uplift_per_unit' => '0.0600']);
        $this->assertSame(['quantity' => 1666.67, 'unit' => 'ml', 'method' => 'uplift'], $this->calc->doseForProduct($uplift, 20, 10, 10));

        $grams = new EnologicalProduct(['name' => 'K2S2O5', 'unit' => 'g']);
        $this->assertSame(['quantity' => 15.0, 'unit' => 'g', 'method' => 'k2s2o5'], $this->calc->doseForProduct($grams, 28, 18, 1000));

        $kg = new EnologicalProduct(['name' => 'K2S2O5', 'unit' => 'kg']);
        $this->assertSame(['quantity' => 0.015, 'unit' => 'kg', 'method' => 'k2s2o5'], $this->calc->doseForProduct($kg, 28, 18, 1000));
    }

    public function test_a_liquid_product_without_uplift_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->calc->doseForProduct(new EnologicalProduct(['name' => 'SO2 5%', 'unit' => 'ml']), 28, 18, 1000);
    }

    public function test_molecular_so2(): void
    {
        // 30 / (1 + 10^(3.5 − 1.81)) = 30 / 49.98
        $this->assertSame(0.6, $this->calc->molecular(30, 3.5));
        $this->assertSame(1.52, $this->calc->molecular(25, 3.0)); // 25 / 16.49
    }

    /** @return array<string, array{float|null, string}> */
    public static function statuses(): array
    {
        return [
            'none' => [null, 'no_data'],
            'at 14.9' => [14.9, 'critical'],
            'at 15' => [15.0, 'low'],
            'at 24.9' => [24.9, 'low'],
            'at 25' => [25.0, 'ok'],
            'at 45' => [45.0, 'ok'],
            'at 45.1' => [45.1, 'high'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_status_buckets(?float $free, string $expected): void
    {
        $this->assertSame($expected, $this->calc->status($free));
    }

    public function test_reference_targets_and_oak(): void
    {
        $this->assertSame(28, $this->calc->referenceTarget(WineType::Red, false));
        $this->assertSame(31, $this->calc->referenceTarget(WineType::Red, true));
        $this->assertSame(35, $this->calc->referenceTarget(WineType::White, false));
        $this->assertSame(32, $this->calc->referenceTarget(WineType::Rose, false));
        $this->assertSame(25, $this->calc->referenceTarget(WineType::Orange, false));
        $this->assertSame(30, $this->calc->referenceTarget(WineType::Sparkling, false));
        $this->assertSame(30, $this->calc->referenceTarget(null, false));

        $this->assertTrue($this->calc->isOak('Hrast', VesselType::Tank));
        $this->assertTrue($this->calc->isOak(null, VesselType::Barrique));
        $this->assertFalse($this->calc->isOak('Stainless', VesselType::Tank));
    }
}
