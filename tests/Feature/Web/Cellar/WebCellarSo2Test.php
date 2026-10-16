<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\CellarActivity;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\EnologicalProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Cellar › SO₂ as an Inertia page (parity area E). */
class WebCellarSo2Test extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    private WineLot $lot;

    private Vessel $tank;

    private EnologicalProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);

        $this->actingAsTenant($this->tenant);
        $this->tank = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '2000.000']);
        $this->lot = WineLot::create(['lot_number' => 'LOT-2026-001', 'name' => 'Plavac', 'grape_variety' => 'Plavac', 'vintage' => '2026',
            'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => '1000.000', 'current_volume' => '1000.000']);
        VesselLot::create(['vessel_id' => $this->tank->getKey(), 'wine_lot_id' => $this->lot->getKey(), 'volume' => '1000.000']);
        CellarAnalysis::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $this->tank->getKey(), 'date' => now()->subDays(3), 'free_so2' => 18, 'created_by_id' => $this->cellar->getKey()]);
        $this->product = EnologicalProduct::create(['name' => 'K2S2O5', 'category' => 'SO2', 'unit' => 'g', 'current_stock' => '100.000', 'cost_per_unit' => 2]);
        $this->forgetTenant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function web(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->actingAs($this->cellar)
            ->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->from('/cellar/so2')
            ->call($method, $uri, $data);
    }

    /** @return array<string, mixed> */
    private function dose(float $mgL = 10): array
    {
        return [
            'enological_product_id' => $this->product->getKey(),
            'items' => [['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $this->tank->getKey(), 'mg_l' => $mgL]],
        ];
    }

    public function test_board_props(): void
    {
        $this->web('GET', '/cellar/so2')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/So2')
            ->where('board.summary.low', 1)
            ->where('board.lots.0.vessels.0.free_so2', 18)
            ->where('board.products.0.name', 'K2S2O5')
            ->missing('members'));
    }

    public function test_preview_returns_the_server_dose_as_json(): void
    {
        $this->actingAs($this->cellar)
            ->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->postJson('/cellar/so2/preview', $this->dose())
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', '15.000')   // 10 mg/L × 1000 L ÷ 1000 × 1.5
            ->assertJsonPath('data.lines.0.target_mg_l', 28)
            ->assertJsonPath('data.totals.short_by', null);
    }

    public function test_apply_books_additions_and_flashes(): void
    {
        $this->web('POST', '/cellar/so2/apply', $this->dose())->assertRedirect('/cellar/so2')->assertSessionHas('success');

        $this->actingAsTenant($this->tenant);
        $addition = CellarAddition::query()->sole();
        $this->assertSame($this->tank->getKey(), $addition->vessel_id);
        $this->assertSame('85.000', (string) $this->product->refresh()->current_stock);
        $this->assertTrue(AuditLog::query()->where('action', 'cellar.so2_applied')->exists());
    }

    public function test_a_stock_shortfall_comes_back_as_an_error(): void
    {
        $this->web('POST', '/cellar/so2/apply', $this->dose(100))->assertSessionHasErrors();
        $this->actingAsTenant($this->tenant);
        $this->assertSame(0, CellarAddition::query()->count());
    }

    public function test_plan_round_and_readings(): void
    {
        $this->web('POST', '/cellar/so2/round', [...$this->dose(), 'planned_for' => '2026-10-15'])->assertSessionHas('success');
        $this->web('POST', '/cellar/so2/readings', ['analyses' => [['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $this->tank->getKey(), 'free_so2' => 31.5]]])
            ->assertSessionHas('success');

        $this->actingAsTenant($this->tenant);
        $this->assertSame(1, CellarActivity::query()->where('type', 'ADDITION')->count());
        $this->assertSame(2, CellarAnalysis::query()->count());
    }
}
