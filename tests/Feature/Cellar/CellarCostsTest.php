<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WineLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Covers CellarCostsQuery + LotCostService::breakdown() ahead of batching the
 * per-lot additions()->sum() query into one whereIn/eager-load. Locks down the
 * exact per-lot totals so a refactor that reshuffles which lot's additions get
 * summed into which row's breakdown is caught immediately.
 */
class CellarCostsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function lot(string $lotNumber, int $grapeCostMinor, string $initialVolume): WineLot
    {
        return WineLot::create([
            'lot_number' => $lotNumber, 'name' => 'Lot '.$lotNumber, 'grape_variety' => 'Plavac',
            'vintage' => '2026', 'wine_type' => 'RED',
            'initial_volume' => $initialVolume, 'current_volume' => $initialVolume,
            'grape_cost' => $grapeCostMinor,
        ]);
    }

    /** @return array<string, mixed> the entry for the given lot from GET /cellar/costs */
    private function costsFor(WineLot $lot): array
    {
        $rows = $this->getJson('/api/v1/cellar/costs', $this->headers())->assertOk()->json('data');

        foreach ($rows as $row) {
            if ($row['id'] === $lot->getKey()) {
                return $row;
            }
        }

        $this->fail("Lot {$lot->getKey()} not present in /cellar/costs response.");
    }

    public function test_breakdown_sums_multiple_additions_for_a_lot(): void
    {
        $this->actingAsTenant($this->tenant);
        $lot = $this->lot('LOT-2026-301', 10_000, '100.000');
        $lot->additions()->create([
            'created_by_id' => $this->admin->getKey(), 'name' => 'SO2', 'quantity' => '10.000', 'unit' => 'g',
            'total_cost' => 3_000,
        ]);
        $lot->additions()->create([
            'created_by_id' => $this->admin->getKey(), 'name' => 'Tannin', 'quantity' => '5.000', 'unit' => 'g',
            'total_cost' => 2_000,
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);

        $cost = $this->costsFor($lot)['cost'];

        // grape 10000 + additions (3000 + 2000) = 15000 total.
        $this->assertSame(10_000, $cost['grape']);
        $this->assertSame(5_000, $cost['additions']);
        $this->assertSame(15_000, $cost['total']);
        $this->assertSame(150, $cost['per_liter']); // 15000 / 100 L
        $this->assertSame(113, $cost['per_bottle_750']); // round(150 * 0.75)
    }

    public function test_breakdown_with_zero_additions_reports_zero_not_null(): void
    {
        $this->actingAsTenant($this->tenant);
        $lot = $this->lot('LOT-2026-302', 8_000, '50.000');
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);

        $cost = $this->costsFor($lot)['cost'];

        $this->assertSame(0, $cost['additions']);
        $this->assertSame(8_000, $cost['total']);
        $this->assertSame(160, $cost['per_liter']); // 8000 / 50 L
        $this->assertSame(120, $cost['per_bottle_750']); // round(160 * 0.75)
    }

    public function test_breakdown_does_not_leak_additions_between_lots(): void
    {
        $this->actingAsTenant($this->tenant);
        $lotA = $this->lot('LOT-2026-303', 1_000, '10.000');
        $lotA->additions()->create([
            'created_by_id' => $this->admin->getKey(), 'name' => 'SO2', 'quantity' => '1.000', 'unit' => 'g',
            'total_cost' => 500,
        ]);
        $lotB = $this->lot('LOT-2026-304', 2_000, '10.000');
        $lotB->additions()->create([
            'created_by_id' => $this->admin->getKey(), 'name' => 'SO2', 'quantity' => '1.000', 'unit' => 'g',
            'total_cost' => 700,
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);

        $rows = $this->getJson('/api/v1/cellar/costs', $this->headers())->assertOk()->json('data');
        $this->assertCount(2, $rows);

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row['cost'];
        }

        // Each lot's breakdown reflects only its own additions — not the
        // other lot's, and not their sum.
        $this->assertSame(500, $byId[$lotA->getKey()]['additions']);
        $this->assertSame(1_500, $byId[$lotA->getKey()]['total']); // 1000 + 500
        $this->assertSame(700, $byId[$lotB->getKey()]['additions']);
        $this->assertSame(2_700, $byId[$lotB->getKey()]['total']); // 2000 + 700
    }
}
