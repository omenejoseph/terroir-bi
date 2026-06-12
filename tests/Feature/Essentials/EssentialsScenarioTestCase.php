<?php

declare(strict_types=1);

namespace Tests\Feature\Essentials;

use App\Enums\StockMovementType;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Base class for the BDD acceptance suite covering the Essentials scenario
 * database (order-mgmt repo: docs/scenarios/essentials.md). Each test method is
 * one Gherkin scenario, named by its scenario id, executed against the real
 * Actions/Queries and database. Shared Given-steps live here.
 *
 * Reference data mirrors the doc: "R3 2025" (bottles, €12.00 list, €5.42 cost),
 * "Sangreal Shiraz 2021" (cases of 6, €60.00/bottle), "Distributor X" (29%
 * rebate), internal outlets with exclude_from_stats.
 */
abstract class EssentialsScenarioTestCase extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        $this->forgetTenant();
        parent::tearDown();
    }

    /**
     * Given "<name>" stored in bottles with <stock> in stock (R3-style).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function givenBottledWine(array $overrides = []): InventoryItem
    {
        static $n = 0;
        $n++;

        return InventoryItem::create(array_merge([
            'name' => 'R3',
            'vintage' => '2025',
            'sku' => 'R3-2025-'.$n.uniqid(),
            'category' => 'FINISHED',
            'unit' => 'bottles',
            'sales_unit' => 'bottles',
            'bottles_per_case' => 6,
            'current_stock' => '100.000',
            'is_for_sale' => true,
            'default_price' => 1200, // €12.00 list, minor units
            'cost_per_unit' => 542,  // €5.42 per bottle
        ], $overrides));
    }

    /**
     * Given "<name>" stored and sold in cases of 6 (Sangreal-style).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function givenCasedWine(array $overrides = []): InventoryItem
    {
        static $n = 0;
        $n++;

        return InventoryItem::create(array_merge([
            'name' => 'Sangreal Shiraz',
            'vintage' => '2021',
            'sku' => 'SHZ-2021-'.$n.uniqid(),
            'category' => 'FINISHED',
            'unit' => 'cases',
            'sales_unit' => 'cases',
            'bottles_per_case' => 6,
            'current_stock' => '50.000',
            'is_for_sale' => true,
            'default_price' => 6000, // €60.00 per bottle
            'cost_per_unit' => 12000, // per case
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function givenCustomer(array $overrides = []): Customer
    {
        static $n = 0;
        $n++;

        return Customer::create(array_merge([
            'company_name' => 'Customer '.$n.uniqid(),
            'email' => 'c'.$n.uniqid().'@example.com',
        ], $overrides));
    }

    /** Then current stock of the item is … */
    protected function stockOf(InventoryItem $item): float
    {
        return (float) InventoryItem::query()->whereKey($item->getKey())->firstOrFail()->current_stock;
    }

    /** @return list<StockMovement> */
    protected function movementsOf(InventoryItem $item): array
    {
        return array_values(StockMovement::query()
            ->where('inventory_item_id', $item->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** @return list<StockMovement> */
    protected function deductionsOf(InventoryItem $item): array
    {
        return array_values(array_filter(
            $this->movementsOf($item),
            fn (StockMovement $m) => $m->type === StockMovementType::OrderDeduct,
        ));
    }
}
