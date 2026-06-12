<?php

declare(strict_types=1);

namespace Tests\Feature\Essentials;

use App\Actions\Orders\CreateOrderAction;
use App\Models\CustomerPrice;
use App\Models\PricingTier;
use App\Models\TierPrice;
use App\Services\Pricing\PricingService;
use App\Support\Money\Money;

/**
 * Feature: Orders — pricing, costs & profitability
 * (ORD-014, ORD-016 … ORD-018, ORD-020, ORD-027 pricing half).
 */
class OrdersPricingTest extends EssentialsScenarioTestCase
{
    public function test_ord014_rebate_is_stored_net_and_never_applied_twice(): void
    {
        // Given "Distributor X" has a 29% rebate and "R3 2025" lists at €12.00
        $distributorX = $this->givenCustomer(['company_name' => 'Distributor X', 'rebate_percent' => 29]);
        $r3 = $this->givenBottledWine(['default_price' => 1200]);

        // When an order line is created
        $order = app(CreateOrderAction::class)->execute($distributorX, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
        ]);

        // Then unitPrice stores €8.52 (net)
        $line = $order->items()->firstOrFail();
        $this->assertSame(852, $line->unit_price->getMinorAmount());

        // And gross is reconstructed as net/(1−pct), never net×0.71 again
        $gross = (int) round($line->unit_price->getMinorAmount() / (1 - 0.29));
        $this->assertSame(1200, $gross);
    }

    public function test_ord014_customer_without_a_rebate_shows_no_rebate(): void
    {
        // Given "BIBICh Degustacije Plastovo" has no rebate configured
        $internal = $this->givenCustomer([
            'company_name' => 'BIBICh Degustacije Plastovo',
            'rebate_percent' => 0,
            'exclude_from_stats' => true,
        ]);
        $r3 = $this->givenBottledWine(['default_price' => 1200]);

        // When their price resolves
        $price = app(PricingService::class)->resolve($internal, $r3);

        // Then it is plain list — no rebate inferred from anything
        $this->assertSame(1200, $price->getMinorAmount());
        $this->assertSame(0.0, (float) $internal->effectiveRebatePercent());
    }

    public function test_ord014_negotiated_customer_price_is_absolute_no_rebate_on_top(): void
    {
        // Given a customer with a 29% rebate AND a negotiated fixed price
        $customer = $this->givenCustomer(['rebate_percent' => 29]);
        $r3 = $this->givenBottledWine(['default_price' => 1200]);
        CustomerPrice::create([
            'customer_id' => $customer->getKey(),
            'inventory_item_id' => $r3->getKey(),
            'price' => 900,
        ]);

        // Then the negotiated price wins as-is — the rebate is NOT applied again
        $price = app(PricingService::class)->resolve($customer, $r3);
        $this->assertSame(900, $price->getMinorAmount());
    }

    public function test_ord027_tier_price_with_customer_rebate(): void
    {
        // Given a tier-priced catalog and a customer on that tier
        $tier = PricingTier::create(['name' => 'Distribution', 'rebate_percent' => 0]);
        $customer = $this->givenCustomer(['rebate_percent' => 29, 'pricing_tier_id' => $tier->getKey()]);
        $r3 = $this->givenBottledWine(['default_price' => 1200]);
        TierPrice::create(['pricing_tier_id' => $tier->getKey(), 'inventory_item_id' => $r3->getKey(), 'price' => 1000]);

        // Then the portal/staff price is the tier price with the rebate applied
        $price = app(PricingService::class)->resolve($customer, $r3);
        $this->assertSame(710, $price->getMinorAmount()); // 1000 × (1 − 0.29)
    }

    public function test_ord016_cost_snapshot_frozen_at_creation(): void
    {
        // Given "R3 2025" cost resolves to €5.42/bottle today
        $r3 = $this->givenBottledWine(['cost_per_unit' => 542]);
        $customer = $this->givenCustomer();

        // When an order line for it is created
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 10, 'unit_type' => 'bottles']],
        ]);

        // Then OrderItem.costPerUnit stores €5.42
        $line = $order->items()->firstOrFail();
        $this->assertSame(542, $line->cost_per_unit?->getMinorAmount());

        // When the cost changes to €6.10 next month
        $r3->update(['cost_per_unit' => 610]);

        // Then the order's profitability still uses €5.42 — history not rewritten
        $this->assertSame(542, $order->items()->firstOrFail()->cost_per_unit?->getMinorAmount());
    }

    public function test_ord018_logistics_cost_means_we_pay_it(): void
    {
        // Given an order with items totalling €1,000.00 and COGS €450.00
        $wine = $this->givenBottledWine(['default_price' => 10000, 'cost_per_unit' => 4500]);
        $customer = $this->givenCustomer();

        // When a logistics cost of €80.00 is entered
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'shipping_cost' => 8000,
            'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 10, 'unit_type' => 'bottles']],
        ]);

        // Then gross profit shows €470.00 (1000 − 450 − 80)
        $order->refresh();
        $revenue = $order->total_amount->getMinorAmount();
        $cogs = $order->items->sum(fn ($i) => ($i->cost_per_unit?->getMinorAmount() ?? 0) * $i->quantity);
        $shipping = $order->shipping_cost?->getMinorAmount() ?? 0;
        $this->assertSame(100000, $revenue);
        $this->assertSame(45000, $cogs);
        $this->assertSame(47000, $revenue - $cogs - $shipping);

        // And a stored cost means we pay — no separate toggle needed
        $this->assertTrue($order->shipping_paid_by_us);
    }

    public function test_ord020_order_total_arithmetic(): void
    {
        // Given lines of €720.00 and €180.00
        $shiraz = $this->givenCasedWine(['default_price' => 6000]); // €60/bottle → €360/case
        $r3 = $this->givenBottledWine(['default_price' => 1800]);   // €18/bottle
        $customer = $this->givenCustomer();

        // When an order is created with both lines and €50.00 logistics
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'shipping_cost' => 5000,
            'items' => [
                ['inventory_item_id' => $shiraz->getKey(), 'quantity' => 2, 'unit_type' => 'cases'],   // €720
                ['inventory_item_id' => $r3->getKey(), 'quantity' => 10, 'unit_type' => 'bottles'],    // €180
            ],
        ]);

        // Then order totalAmount = €900.00 (lines only; logistics tracked apart)
        $order->refresh();
        $this->assertSame(90000, $order->total_amount->getMinorAmount());

        // And gross profit = 900 − (line COGS from snapshots) − 50
        $cogs = $order->items->sum(fn ($i) => ($i->cost_per_unit?->getMinorAmount() ?? 0) * $i->quantity);
        $grossProfit = 90000 - $cogs - 5000;
        $this->assertSame($grossProfit, $order->total_amount->getMinorAmount() - $cogs - ($order->shipping_cost?->getMinorAmount() ?? 0));
        $this->assertGreaterThan(0, $cogs); // snapshots actually contributed
    }

    public function test_ord017_custom_lines_carry_settable_costs(): void
    {
        // When a custom line "Wooden gift box" is added at price €15.00
        $customer = $this->givenCustomer();
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [
                ['custom_description' => 'Wooden gift box', 'quantity' => 1, 'unit_type' => 'bottles', 'unit_price' => 1500],
            ],
        ]);

        // Then the line carries the custom description and no phantom cost
        $line = $order->items()->firstOrFail();
        $this->assertSame('Wooden gift box', $line->custom_description);
        $this->assertSame(1500, $line->unit_price->getMinorAmount());
        $this->assertNull($line->cost_per_unit);

        // When the admin sets its cost later (from the order detail)
        $currency = $line->unit_price->getCurrencyCode();
        $line->update(['cost_per_unit' => Money::fromMinor(600, $currency)]);

        // Then profitability recomputes with the new cost
        $this->assertSame(600, $order->items()->firstOrFail()->cost_per_unit?->getMinorAmount());
    }
}
