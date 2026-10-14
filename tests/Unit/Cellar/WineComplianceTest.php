<?php

declare(strict_types=1);

namespace Tests\Unit\Cellar;

use App\Enums\WineType;
use App\Services\Cellar\EnologicalCalculator;
use App\Services\Cellar\WineCompliance;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WineComplianceTest extends TestCase
{
    /** @return array<string, array{WineType|null, float|null, int}> */
    public static function limits(): array
    {
        return [
            'dry red' => [WineType::Red, 2.0, 150],
            'sweet red' => [WineType::Red, 6.0, 200],
            'dry white' => [WineType::White, null, 200],
            'sweet white' => [WineType::White, 12.0, 250],
            'dry rosé' => [WineType::Rose, 1.0, 200],
            'orange' => [WineType::Orange, 1.0, 200],
            'dessert' => [WineType::Dessert, 80.0, 250],
            'sparkling' => [WineType::Sparkling, 10.0, 185],
            'unknown' => [null, null, 200],
        ];
    }

    #[DataProvider('limits')]
    public function test_eu_total_so2_limits(?WineType $type, ?float $sugar, int $limit): void
    {
        $this->assertSame($limit, (new WineCompliance)->so2Limit($type, $sugar));
    }

    public function test_check_reports_margin(): void
    {
        $this->assertSame(['limit' => 150, 'compliant' => true, 'margin' => 29.5], (new WineCompliance)->checkSo2(120.5, WineType::Red, 1));
        $this->assertFalse((new WineCompliance)->checkSo2(151, WineType::Red, 1)['compliant']);
    }

    public function test_enological_calculators(): void
    {
        $c = new EnologicalCalculator;
        $this->assertSame(['grams_tartaric_acid' => 670.0], $c->acidAddition(1000, 5.0, 6.0));
        $this->assertSame(['grams_needed' => 50.0], $c->generalAddition(1000, 50));
        $this->assertSame(['grams_needed' => 100.0], $c->generalAddition(1000, 50, 50));
        $this->assertSame(['kg_sugar' => 16.67, 'potential_alcohol_increase' => 0.6], $c->chaptalization(1000, 21, 22));

        $yield = $c->pressYield(1000, 0.65, [['type' => 'free run', 'volume_liters' => 500], ['type' => 'press', 'volume_liters' => 150]]);
        $this->assertSame(650.0, $yield['total_juice_liters']);
        $this->assertSame(65.0, $yield['overall_yield']);
        $this->assertSame(76.9, $yield['fractions'][0]['percent']);
    }
}
