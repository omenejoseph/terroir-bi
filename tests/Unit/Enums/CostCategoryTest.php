<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CostCategory;
use App\Queries\InflowAnalyticsQuery;
use App\Queries\ListCostsQuery;
use PHPUnit\Framework\TestCase;

class CostCategoryTest extends TestCase
{
    public function test_defaults_include_computation_critical_categories(): void
    {
        $defaults = CostCategory::defaults();

        // The categories the dashboard ratios and cost/inflow cards depend on.
        $this->assertContains('Salary', $defaults);
        $this->assertContains('Marketing', $defaults);
        $this->assertContains('Invoice', $defaults);
        $this->assertContains('Payment', $defaults);
    }

    public function test_matches_is_case_insensitive_and_trims(): void
    {
        $this->assertTrue(CostCategory::Salary->matches('salary'));
        $this->assertTrue(CostCategory::Salary->matches('  SALARY '));
        $this->assertTrue(CostCategory::Marketing->matches('marketing'));
        $this->assertFalse(CostCategory::Salary->matches('marketing'));
        $this->assertFalse(CostCategory::Salary->matches(null));
    }

    public function test_query_constants_stay_aligned_with_the_enum(): void
    {
        // Guards against drift if someone edits a query constant by hand.
        $this->assertSame(CostCategory::Invoice->value, ListCostsQuery::INVOICE_CATEGORY);
        $this->assertSame(CostCategory::Payment->value, ListCostsQuery::PAYMENT_CATEGORY);
        $this->assertSame(CostCategory::Invoice->value, InflowAnalyticsQuery::INVOICE_CATEGORY);
    }
}
