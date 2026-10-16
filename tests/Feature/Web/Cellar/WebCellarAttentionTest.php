<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\CellarActivity;
use App\Models\CellarAnalysis;
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

/** Cellar › "needs attention" (Phase 3.7): what's wrong, with which wine, and the action that deals with it. */
class WebCellarAttentionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    private WineLot $red;

    private WineLot $white;

    private Vessel $t1;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 09:00:00');
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);

        $this->actingAsTenant($this->tenant);
        $this->t1 = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '3000.000']);
        $t2 = Vessel::create(['name' => 'T2', 'type' => 'TANK', 'capacity_liters' => '3000.000']);
        $this->red = WineLot::create(['lot_number' => 'R-1', 'name' => 'Ostro Crna', 'grape_variety' => 'Plavac', 'vintage' => '2023', 'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => '2750.000', 'current_volume' => '2750.000']);
        $this->white = WineLot::create(['lot_number' => 'W-1', 'name' => 'Velika Bjelica', 'grape_variety' => 'Pošip', 'vintage' => '2022', 'wine_type' => 'WHITE', 'status' => 'READY', 'initial_volume' => '1100.000', 'current_volume' => '1100.000']);
        VesselLot::create(['vessel_id' => $this->t1->getKey(), 'wine_lot_id' => $this->red->getKey(), 'volume' => '2750.000']);
        VesselLot::create(['vessel_id' => $t2->getKey(), 'wine_lot_id' => $this->white->getKey(), 'volume' => '1100.000']);
        $by = $this->cellar->getKey();
        // Red: last analysis 38 days ago, cold, free SO₂ low.
        CellarAnalysis::create(['wine_lot_id' => $this->red->getKey(), 'vessel_id' => $this->t1->getKey(), 'date' => '2026-09-08', 'free_so2' => 18, 'temperature' => 15.9, 'created_by_id' => $by]);
        // White: fresh and fine, but a task is overdue.
        CellarAnalysis::create(['wine_lot_id' => $this->white->getKey(), 'vessel_id' => $t2->getKey(), 'date' => '2026-10-15', 'free_so2' => 30, 'created_by_id' => $by]);
        CellarActivity::create(['type' => 'PROCESS', 'planned_for' => '2026-10-14', 'wine_lot_id' => $this->white->getKey(), 'vessel_id' => $t2->getKey(), 'planned_by_id' => $by, 'payload' => ['kind' => 'Pump-over']]);
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
        return $this->actingAs($this->cellar)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])->from('/cellar')->call($method, $uri, $data);
    }

    /** @return list<array<string, mixed>> */
    private function attention(string $uri = '/cellar'): array
    {
        $items = [];
        $this->web('GET', $uri)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$items): void {
            $items = $page->toArray()['props']['attention'];
        });

        return $items;
    }

    public function test_the_cellar_home_lists_what_needs_attention_with_one_action_each(): void
    {
        $items = [];
        foreach ($this->attention() as $i) {
            $items[explode(':', $i['key'])[1]] = $i;
        }

        // Severity first: high (stale analysis, low SO₂) before medium (cold, overdue).
        $this->assertSame('high', $this->attention()[0]['severity']);

        $stale = $items['stale_analysis'] ?? $this->fail('no stale analysis item');
        $this->assertSame('Ostro Crna', $stale['lot_name']);
        $this->assertSame('plan_reading', $stale['action']['kind']);
        $this->assertSame('/cellar/activities', $stale['action']['post']['url']);
        $this->assertSame([['type' => 'ANALYSIS', 'planned_for' => '2026-10-16', 'wine_lot_id' => $this->red->getKey(), 'vessel_id' => $this->t1->getKey(), 'payload' => ['metrics' => ['free_so2', 'total_so2', 'ph']]]], $stale['action']['post']['data']['activities']);

        $this->assertSame('/cellar/so2?lots='.$this->red->getKey(), $items['so2_low']['action']['href']);
        $this->assertSame('process_task', $items['temperature']['action']['kind']);
        $this->assertSame(rtrim(__('Warm the vessel.', [], 'hr'), '.'), $items['temperature']['action']['post']['data']['activities'][0]['payload']['kind']);
        $this->assertSame('open_today', $items['overdue']['action']['kind']);
        $this->assertSame('Velika Bjelica', $items['overdue']['lot_name']);
        $this->assertArrayNotHasKey('so2_stale', $items, 'the stale analysis already covers the SO₂ reading');
    }

    public function test_taking_the_action_plans_the_work_and_then_the_item_says_so(): void
    {
        $stale = collect($this->attention())->firstWhere('action.kind', 'plan_reading') ?? $this->fail('no plan_reading item');
        $this->web('POST', $stale['action']['post']['url'], $stale['action']['post']['data'])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAsTenant($this->tenant);
        $this->assertSame(1, CellarActivity::query()->where('type', 'ANALYSIS')->where('wine_lot_id', $this->red->getKey())->count());
        $this->forgetTenant();

        $again = collect($this->attention())->firstWhere('key', $this->red->getKey().':stale_analysis') ?? $this->fail('no stale analysis item');
        $this->assertSame('open_today', $again['action']['kind']);
        $this->assertNull($again['action']['post']);
    }

    public function test_a_lot_page_shows_only_its_own_items(): void
    {
        $items = $this->attention('/cellar/lots/'.$this->white->getKey());
        $this->assertSame(['overdue'], array_map(fn (array $i): string => explode(':', $i['key'])[1], $items));
    }
}
