<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\Bottling;
use App\Models\CellarAddition;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WineLot;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Cellar › Analytics, Costs, Cellar book and the calculators as Inertia pages (parity areas N, O). */
class WebCellarAnalyticsCostsBookTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->actingAsTenant($this->tenant);
        $aging = WineLot::create(['lot_number' => 'LOT-2026-001', 'name' => 'Plavac', 'grape_variety' => 'Plavac', 'vintage' => '2026', 'status' => 'AGING', 'initial_volume' => '1000.000', 'current_volume' => '1000.000', 'grape_cost' => 200000]);
        CellarAddition::create(['wine_lot_id' => $aging->getKey(), 'created_by_id' => $this->admin->getKey(), 'name' => 'Bentonite', 'quantity' => '100.000', 'unit' => 'g', 'total_cost' => 5000]);
        $bottled = WineLot::create(['lot_number' => 'LOT-2025-001', 'name' => 'Pošip', 'grape_variety' => 'Pošip', 'vintage' => '2025', 'status' => 'BOTTLED', 'initial_volume' => '750.000', 'current_volume' => '0.000', 'grape_cost' => 75000]);
        $item = InventoryItem::create(['name' => 'Pošip 2025', 'sku' => 'POS25', 'category' => 'FINISHED', 'unit' => 'bottles', 'default_price' => 1500]);
        Bottling::create(['wine_lot_id' => $bottled->getKey(), 'inventory_item_id' => $item->getKey(), 'created_by_id' => $this->admin->getKey(), 'date' => now(), 'bottle_count' => 1000, 'bottle_volume_ml' => 750, 'volume_used' => '750.000']);
        $this->forgetTenant();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function web(string $method, string $uri, array $data = [], ?User $as = null, bool $json = false): TestResponse
    {
        $req = $this->actingAs($as ?? $this->cellar)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()]);

        return $json ? $req->json($method, $uri, $data) : $req->call($method, $uri, $data);
    }

    public function test_analytics_hides_money_without_finance(): void
    {
        $this->web('GET', '/cellar/analytics')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Analytics')
            ->where('analytics.kpis.wine_in_cellar', 1000)
            ->where('analytics.kpis.average_cost_per_liter', null)
            ->has('analytics.timeline', 12)
            ->where('waterfall', null));

        $this->web('GET', '/cellar/analytics', as: $this->admin)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analytics.kpis.average_cost_per_liter', 205)
            ->where('waterfall.0.name', 'Plavac'));
    }

    public function test_costs_page_is_for_finance_and_includes_bottled_margin(): void
    {
        $this->web('GET', '/cellar/costs')->assertForbidden();

        $this->web('GET', '/cellar/costs', as: $this->admin)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Costs')
            ->has('lots', 2)
            ->where('lots.0.name', 'Plavac')
            ->where('lots.0.cost.total', 205000)
            ->where('lots.0.additions.0.total_cost', 5000)
            ->where('lots.1.name', 'Pošip')
            ->where('lots.1.selling_price', 1500)
            ->where('lots.1.bottle_volume_ml', 750));
    }

    public function test_cellar_book_table_csv_and_calculators(): void
    {
        $from = now()->subMonth()->toDateString();
        $to = now()->toDateString();

        $this->web('GET', "/cellar/book?from={$from}&to={$to}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/CellarBook')
            ->where('filters.from', $from)
            ->where('rows', fn ($rows): bool => count($rows) >= 4));

        $csv = $this->web('GET', "/cellar/book.csv?from={$from}&to={$to}");
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('Bottling', $csv->streamedContent());

        $this->web('POST', '/cellar/calculators', ['kind' => 'so2', 'volume_liters' => 1000, 'current_free_so2' => 20, 'target_free_so2' => 30, 'ph' => 3.5], json: true)
            ->assertOk()->assertJsonPath('data.grams_k2s2o5', 15);
        $this->web('POST', '/cellar/calculators', ['kind' => 'so2_compliance', 'total_so2' => 160, 'wine_type' => 'RED'], json: true)
            ->assertOk()->assertJsonPath('data.compliant', false);
    }
}
