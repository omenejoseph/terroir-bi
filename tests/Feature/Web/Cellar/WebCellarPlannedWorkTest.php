<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Models\WorkOrder;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Cellar › Today and Cellar › Plan as Inertia pages (parity area J). */
class WebCellarPlannedWorkTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function inTenant(callable $fn): mixed
    {
        $this->actingAsTenant($this->tenant);
        try {
            return $fn();
        } finally {
            $this->forgetTenant();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function web(string $method, string $uri, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->cellar)
            ->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->from('/cellar/today')
            ->call($method, $uri, $data);
    }

    /**
     * @return array{0: WineLot, 1: Vessel, 2: Vessel}
     */
    private function seedLot(): array
    {
        return $this->inTenant(function (): array {
            $a = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '1000.000', 'room' => 'Hall', 'position_x' => 10, 'position_y' => 10]);
            $b = Vessel::create(['name' => 'T2', 'type' => 'TANK', 'capacity_liters' => '1000.000', 'room' => 'Hall']);
            $lot = WineLot::create(['lot_number' => 'LOT-2026-001', 'name' => 'Plavac', 'grape_variety' => 'Plavac', 'vintage' => '2026',
                'wine_type' => 'RED', 'initial_volume' => '800.000', 'current_volume' => '800.000']);
            foreach ([$a, $b] as $v) {
                VesselLot::create(['vessel_id' => $v->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => '400.000']);
                $v->forceFill(['current_volume' => '400.000', 'status' => 'IN_USE'])->save();
            }

            return [$lot, $a, $b];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function plan(WineLot $lot, Vessel $vessel, string $type = 'PROCESS', array $payload = ['kind' => 'Pump-over'], ?string $round = null): CellarActivity
    {
        $this->web('POST', '/cellar/activities', [
            'round_name' => $round,
            'activities' => [[
                'type' => $type, 'planned_for' => '2026-10-14',
                'wine_lot_id' => $lot->getKey(), 'vessel_id' => $vessel->getKey(), 'payload' => $payload,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $this->inTenant(fn () => CellarActivity::query()->latest('id')->firstOrFail());
    }

    public function test_cellar_home_redirects_to_today_and_the_member_lands_there(): void
    {
        $this->web('GET', '/cellar')->assertRedirect('/cellar/today');
        $this->web('GET', '/dashboard')->assertRedirect(route('cellar'));
    }

    public function test_today_lists_open_work_by_room_with_counts_and_mine_filter(): void
    {
        [$lot, $a] = $this->seedLot();
        $other = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $this->plan($lot, $a);
        $this->web('POST', '/cellar/activities', [
            'assigned_to_id' => $other->getKey(),
            'activities' => [['type' => 'PROCESS', 'planned_for' => '2026-10-13', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $a->getKey(), 'payload' => ['kind' => 'Batonnage']]],
        ])->assertSessionHasNoErrors();

        $this->web('GET', '/cellar/today')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Cellar/Today')
                ->where('mine', false)
                ->where('today.counts.open', 2)
                ->where('today.counts.mine', 1)
                ->where('today.open.0.room', 'Hall')
                ->has('today.open.0.items', 2));

        $this->web('GET', '/cellar/today?mine=1')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mine', true)->has('today.open.0.items', 1));
    }

    public function test_complete_skip_and_reschedule_from_today(): void
    {
        [$lot, $a, $b] = $this->seedLot();
        $done = $this->plan($lot, $a);
        $skipped = $this->plan($lot, $b);
        $moved = $this->plan($lot, $a, 'TOPPING', ['liters' => 5]);

        $this->web('POST', "/cellar/activities/{$done->getKey()}/complete")->assertRedirect('/cellar/today');
        $this->web('POST', "/cellar/activities/{$skipped->getKey()}/skip", ['reason' => 'not_needed'])->assertRedirect();
        $this->web('POST', "/cellar/activities/{$moved->getKey()}/reschedule", ['planned_for' => '2026-10-15', 'reason' => 'No time'])->assertSessionHas('success');

        $this->inTenant(function () use ($done, $skipped, $moved): void {
            $this->assertSame('DONE', $done->refresh()->status->value);
            $this->assertSame('SKIPPED', $skipped->refresh()->status->value);
            $this->assertSame('2026-10-15', $moved->refresh()->planned_for->toDateString());
            $this->assertTrue(AuditLog::query()->where('action', 'cellar_activity.completed')->exists());
        });

        // Completing twice is refused with a message the page shows.
        $this->web('POST', "/cellar/activities/{$done->getKey()}/complete")->assertRedirect()->assertSessionHasErrors();

        $this->web('GET', '/cellar/today')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('today.counts.done_today', 1)
            ->has('today.completed', 2));
    }

    public function test_plan_page_offers_pickers_and_a_round_plans_a_board_card(): void
    {
        [$lot, $a, $b] = $this->seedLot();

        $this->web('GET', '/cellar/plan')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Plan')
            ->has('lots', 1)
            ->where('lots.0.vessels.0.name', 'T1')
            ->has('vessels', 2)
            ->has('members', 1)
            ->has('plan.open', 0));

        $this->web('POST', '/cellar/activities', [
            'round_name' => 'Free SO₂ · Wed 14 Oct',
            'activities' => array_map(fn (Vessel $v): array => [
                'type' => 'ANALYSIS', 'planned_for' => '2026-10-16',
                'wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(), 'payload' => ['metrics' => ['free_so2']],
            ], [$a, $b]),
        ])->assertRedirect()->assertSessionHas('success');

        $round = $this->inTenant(fn () => CellarActivityRound::query()->sole());
        $this->inTenant(fn () => $this->assertNotNull(WorkOrder::query()->find($round->work_order_id)));

        $this->web('GET', '/cellar/plan')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.open.0.date', '2026-10-16')
            ->where('plan.open.0.rounds.0.name', 'Free SO₂ · Wed 14 Oct')
            ->has('plan.open.0.rounds.0.items', 2));

        $this->web('POST', "/cellar/activity-rounds/{$round->getKey()}/reschedule", ['planned_for' => '2026-10-20'])->assertSessionHas('success');
        $this->inTenant(fn () => $this->assertSame(2, CellarActivity::query()->whereDate('planned_for', '2026-10-20')->count()));

        $this->web('DELETE', "/cellar/activity-rounds/{$round->getKey()}")->assertSessionHas('success');
        $this->inTenant(fn () => $this->assertSame(0, CellarActivity::query()->count()));
    }

    public function test_invalid_payload_errors_come_back_on_the_field(): void
    {
        [$lot, $a] = $this->seedLot();

        $this->web('POST', '/cellar/activities', [
            'activities' => [['type' => 'ADDITION', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $a->getKey(), 'payload' => ['product_name' => 'Bentonite']]],
        ])->assertSessionHasErrors();
    }

    /**
     * @return array<string, array{TenantRole, bool}>
     */
    public static function roles(): array
    {
        return [
            'cellar' => [TenantRole::Cellar, true],
            'admin' => [TenantRole::Admin, true],
            'team' => [TenantRole::Team, false],
            'orders' => [TenantRole::Orders, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_only_cellar_roles_reach_the_pages_or_plan(TenantRole $role, bool $allowed): void
    {
        $member = $this->createMember($this->tenant, [$role]);
        [$lot, $a] = $this->seedLot();

        foreach (['/cellar/today', '/cellar/plan', '/cellar/so2'] as $uri) {
            $response = $this->web('GET', $uri, as: $member);
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }

        $post = $this->web('POST', '/cellar/activities', ['activities' => [[
            'type' => 'PROCESS', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $a->getKey(), 'payload' => ['kind' => 'X'],
        ]]], $member);
        $allowed ? $post->assertRedirect() : $post->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/cellar/today')->assertRedirect('/login');
    }
}
