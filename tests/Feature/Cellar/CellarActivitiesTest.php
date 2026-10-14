<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TaskStatus;
use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTransfer;
use App\Models\EnologicalProduct;
use App\Models\FermentationTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Models\WorkOrder;
use App\Models\WorkOrderBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Planned cellar work: plan → Today → complete / skip / move / delete. */
class CellarActivitiesTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
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

    private function vessel(string $name, string $capacity = '1000.000', string $room = 'Main Cellar'): Vessel
    {
        return Vessel::create(['name' => $name, 'type' => 'TANK', 'capacity_liters' => $capacity, 'room' => $room]);
    }

    private function lotIn(string $number, string $volume, Vessel ...$vessels): WineLot
    {
        $lot = WineLot::create([
            'lot_number' => $number, 'name' => 'Lot '.$number, 'grape_variety' => 'Plavac', 'vintage' => '2026',
            'wine_type' => 'RED', 'initial_volume' => $volume, 'current_volume' => $volume,
        ]);
        $share = count($vessels) > 0 ? number_format((float) $volume / count($vessels), 3, '.', '') : $volume;
        foreach ($vessels as $v) {
            VesselLot::create(['vessel_id' => $v->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => $share]);
            $v->forceFill(['current_volume' => $share, 'status' => 'IN_USE'])->save();
        }

        return $lot;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function api(string $method, string $uri, array $body = [], ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->admin);

        return $this->json($method, '/api/v1/'.$uri, $body, $this->tenantHeader($this->tenant));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function item(string $type, ?WineLot $lot, ?Vessel $vessel, array $payload, string $date = '2026-10-14'): array
    {
        return array_filter([
            'type' => $type,
            'planned_for' => $date,
            'wine_lot_id' => $lot?->getKey(),
            'vessel_id' => $vessel?->getKey(),
            'payload' => $payload,
        ], fn ($v) => $v !== null);
    }

    public function test_planning_a_round_creates_items_and_a_card_on_the_default_board(): void
    {
        [$lot, $v1, $v2, $default] = $this->inTenant(function () {
            WorkOrderBoard::create(['name' => 'First', 'created_by_id' => $this->admin->getKey(), 'sort_order' => 0]);
            $default = WorkOrderBoard::create(['name' => 'Team', 'created_by_id' => $this->admin->getKey(), 'sort_order' => 5, 'is_default' => true]);
            $v1 = $this->vessel('F11');
            $v2 = $this->vessel('F12');

            return [$this->lotIn('LOT-2026-001', '1000.000', $v1, $v2), $v1, $v2, $default];
        });

        $res = $this->api('POST', 'cellar/activities', [
            'round_name' => 'SO₂ check',
            'activities' => [
                $this->item('ANALYSIS', $lot, $v1, ['metrics' => ['free_so2']], '2026-10-15'),
                $this->item('ANALYSIS', $lot, $v2, ['metrics' => ['free_so2', 'ph']], '2026-10-14'),
            ],
        ])->assertCreated()->assertJsonPath('data.count', 2);

        $this->inTenant(function () use ($res, $default): void {
            $round = CellarActivityRound::query()->findOrFail((string) $res->json('data.round_id'));
            $card = WorkOrder::query()->findOrFail($round->work_order_id);
            $this->assertSame('🍇 SO₂ check', $card->title);
            $this->assertSame($default->getKey(), $card->board_id);
            $this->assertSame('2026-10-14', $card->due_date?->toDateString());
            $this->assertSame(2, $round->activities()->count());
            $this->assertTrue(AuditLog::query()->where('action', 'cellar_activity_round.planned')->exists());
            $this->assertSame(2, AuditLog::query()->where('action', 'cellar_activity.created')->count());
        });
    }

    public function test_round_card_falls_back_to_the_first_board_when_none_is_default(): void
    {
        [$lot, $vessel, $first] = $this->inTenant(function () {
            $first = WorkOrderBoard::create(['name' => 'First', 'created_by_id' => $this->admin->getKey(), 'sort_order' => 0]);
            WorkOrderBoard::create(['name' => 'Second', 'created_by_id' => $this->admin->getKey(), 'sort_order' => 1]);
            $v = $this->vessel('F11');

            return [$this->lotIn('LOT-2026-001', '500.000', $v), $v, $first];
        });

        $res = $this->api('POST', 'cellar/activities', [
            'round_name' => 'Pump-overs',
            'activities' => [$this->item('PROCESS', $lot, $vessel, ['kind' => 'Pump-over'])],
        ])->assertCreated();

        $this->inTenant(fn () => $this->assertSame(
            $first->getKey(),
            WorkOrder::query()->findOrFail((string) $res->json('data.work_order_id'))->board_id,
        ));
    }

    public function test_payload_is_validated_per_type_and_vessel_must_hold_the_lot(): void
    {
        [$lot, $other] = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $lot = $this->lotIn('LOT-2026-001', '500.000', $v);

            return [$lot, $this->vessel('EMPTY')];
        });

        $this->api('POST', 'cellar/activities', [
            'activities' => [$this->item('ADDITION', $lot, null, ['product_name' => 'Bentonite', 'unit' => 'g'])],
        ])->assertUnprocessable()->assertJsonValidationErrors(['activities.0.payload.quantity']);

        $this->api('POST', 'cellar/activities', [
            'activities' => [$this->item('ANALYSIS', $lot, $other, ['metrics' => ['free_so2']])],
        ])->assertUnprocessable()->assertJsonValidationErrors(['activities.0.vessel_id']);

        $this->api('POST', 'cellar/activities', [
            'activities' => [$this->item('ANALYSIS', $lot, null, ['metrics' => ['nonsense']])],
        ])->assertUnprocessable()->assertJsonValidationErrors(['activities.0.payload.metrics.0']);
    }

    public function test_completing_an_addition_records_it_on_the_vessel_and_draws_stock(): void
    {
        [$activity, $vessel, $product] = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $lot = $this->lotIn('LOT-2026-001', '500.000', $v);
            $product = EnologicalProduct::create(['name' => 'K2S2O5', 'category' => 'SO2', 'unit' => 'g', 'current_stock' => '100.000']);
            $a = CellarActivity::create([
                'type' => 'ADDITION', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(),
                'planned_by_id' => $this->admin->getKey(),
                'payload' => ['enological_product_id' => $product->getKey(), 'product_name' => 'K2S2O5', 'unit' => 'g', 'quantity' => 30, 'category' => 'SO2'],
            ]);

            return [$a, $v, $product];
        });

        $this->api('POST', "cellar/activities/{$activity->getKey()}/complete", ['quantity' => 25])
            ->assertOk()
            ->assertJsonPath('data.status', 'DONE')
            ->assertJsonPath('data.deviated', true)
            ->assertJsonPath('data.payload.applied.quantity', '25.000');

        $this->inTenant(function () use ($vessel, $product): void {
            $addition = CellarAddition::query()->sole();
            $this->assertSame($vessel->getKey(), $addition->vessel_id);
            $this->assertSame('25.000', (string) $addition->quantity);
            $this->assertSame('75.000', (string) $product->refresh()->current_stock);
            $log = AuditLog::query()->where('action', 'cellar_activity.completed')->sole();
            $this->assertSame(true, $log->metadata['deviated'] ?? null);
        });
    }

    public function test_an_addition_is_refused_when_stock_is_short(): void
    {
        $activity = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $lot = $this->lotIn('LOT-2026-001', '500.000', $v);
            $product = EnologicalProduct::create(['name' => 'Bentonite', 'category' => 'FINING', 'unit' => 'g', 'current_stock' => '10.000']);

            return CellarActivity::create([
                'type' => 'ADDITION', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(),
                'planned_by_id' => $this->admin->getKey(),
                'payload' => ['enological_product_id' => $product->getKey(), 'product_name' => 'Bentonite', 'unit' => 'g', 'quantity' => 50],
            ]);
        });

        $this->api('POST', "cellar/activities/{$activity->getKey()}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->inTenant(function () use ($activity): void {
            $this->assertSame('PLANNED', $activity->refresh()->status->value);
            $this->assertSame(0, CellarAddition::query()->count());
        });
    }

    public function test_completing_an_analysis_needs_a_reading_and_saves_it_for_the_vessel(): void
    {
        [$activity, $vessel] = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $lot = $this->lotIn('LOT-2026-001', '500.000', $v);

            return [CellarActivity::create([
                'type' => 'ANALYSIS', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(),
                'planned_by_id' => $this->admin->getKey(), 'payload' => ['metrics' => ['free_so2', 'ph']],
            ]), $v];
        });

        $this->api('POST', "cellar/activities/{$activity->getKey()}/complete", ['values' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['values']);

        $this->api('POST', "cellar/activities/{$activity->getKey()}/complete", ['values' => ['free_so2' => 28, 'ph' => 3.52]])
            ->assertOk()->assertJsonPath('data.payload.applied.values.free_so2', 28);

        $this->inTenant(function () use ($vessel): void {
            $analysis = CellarAnalysis::query()->sole();
            $this->assertSame($vessel->getKey(), $analysis->vessel_id);
            $this->assertSame('28.00', (string) $analysis->free_so2);
        });
    }

    public function test_completing_a_transfer_racks_the_wine(): void
    {
        [$activity, $from, $to] = $this->inTenant(function () {
            $from = $this->vessel('F11');
            $to = $this->vessel('F12');
            $lot = $this->lotIn('LOT-2026-001', '600.000', $from);

            return [CellarActivity::create([
                'type' => 'TRANSFER', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $from->getKey(),
                'planned_by_id' => $this->admin->getKey(),
                'payload' => ['from_vessel_id' => $from->getKey(), 'to_vessel_id' => $to->getKey(), 'liters' => 600],
            ]), $from, $to];
        });

        $this->api('POST', "cellar/activities/{$activity->getKey()}/complete")->assertOk()->assertJsonPath('data.deviated', false);

        $this->inTenant(function () use ($from, $to): void {
            $this->assertSame('0.000', (string) $from->refresh()->current_volume);
            $this->assertSame('600.000', (string) $to->refresh()->current_volume);
            $this->assertSame('RACK', CellarTransfer::query()->sole()->type->value);
        });
    }

    public function test_completing_a_process_records_it(): void
    {
        $activity = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $lot = $this->lotIn('LOT-2026-001', '500.000', $v);

            return CellarActivity::create([
                'type' => 'PROCESS', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(),
                'planned_by_id' => $this->admin->getKey(), 'payload' => ['kind' => 'Pump-over'],
            ]);
        });

        $this->api('POST', "cellar/activities/{$activity->getKey()}/complete", ['note' => 'twice'])->assertOk();

        $this->inTenant(function (): void {
            $process = CellarProcess::query()->sole();
            $this->assertSame('Pump-over', $process->kind);
            $this->assertSame('twice', $process->note);
        });
    }

    public function test_topping_without_a_source_adds_volume_and_leaves_a_trail(): void
    {
        [$activity, $lot, $vessel] = $this->inTenant(function () {
            $v = $this->vessel('B1', '225.000');
            $lot = $this->lotIn('LOT-2026-001', '220.000', $v);

            return [CellarActivity::create([
                'type' => 'TOPPING', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(),
                'planned_by_id' => $this->admin->getKey(), 'payload' => ['liters' => 4],
            ]), $lot, $v];
        });

        $this->api('POST', "cellar/activities/{$activity->getKey()}/complete")->assertOk();

        $this->inTenant(function () use ($lot, $vessel): void {
            $this->assertSame('224.000', (string) $lot->refresh()->current_volume);
            $this->assertSame('224.000', (string) $vessel->refresh()->current_volume);
            $process = CellarProcess::query()->sole();
            $this->assertSame('Topping', $process->kind);
            $this->assertSame('4.000', (string) $process->volume);
        });
    }

    public function test_topping_from_another_lots_vessel_moves_the_wine(): void
    {
        [$activity, $lot, $source, $sourceLot] = $this->inTenant(function () {
            $barrel = $this->vessel('B1', '225.000');
            $lot = $this->lotIn('LOT-2026-001', '220.000', $barrel);
            $source = $this->vessel('T9', '500.000');
            $sourceLot = $this->lotIn('LOT-2026-002', '100.000', $source);

            return [CellarActivity::create([
                'type' => 'TOPPING', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $barrel->getKey(),
                'planned_by_id' => $this->admin->getKey(), 'payload' => ['liters' => 5, 'source_vessel_id' => $source->getKey()],
            ]), $lot, $source, $sourceLot];
        });

        $this->api('POST', "cellar/activities/{$activity->getKey()}/complete")->assertOk();

        $this->inTenant(function () use ($lot, $source, $sourceLot): void {
            $this->assertSame('225.000', (string) $lot->refresh()->current_volume);
            $this->assertSame('95.000', (string) $sourceLot->refresh()->current_volume);
            $this->assertSame('95.000', (string) $source->refresh()->current_volume);
            $transfer = CellarTransfer::query()->sole();
            $this->assertSame('BLEND', $transfer->type->value);
            $this->assertSame($sourceLot->getKey(), $transfer->from_lot_id);
        });
    }

    public function test_work_cannot_be_completed_twice_or_after_a_skip(): void
    {
        [$done, $skipped] = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $lot = $this->lotIn('LOT-2026-001', '500.000', $v);
            $make = fn () => CellarActivity::create([
                'type' => 'PROCESS', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(),
                'planned_by_id' => $this->admin->getKey(), 'payload' => ['kind' => 'Pump-over'],
            ]);

            return [$make(), $make()];
        });

        $this->api('POST', "cellar/activities/{$done->getKey()}/complete")->assertOk();
        $this->api('POST', "cellar/activities/{$done->getKey()}/complete")->assertUnprocessable();

        $this->api('POST', "cellar/activities/{$skipped->getKey()}/skip", ['reason' => 'not_needed'])
            ->assertOk()->assertJsonPath('data.status', 'SKIPPED')->assertJsonPath('data.skip_reason', 'not_needed');
        $this->api('POST', "cellar/activities/{$skipped->getKey()}/complete")->assertUnprocessable();

        $this->inTenant(fn () => $this->assertSame(1, CellarProcess::query()->count()));
        $this->inTenant(fn () => $this->assertTrue(AuditLog::query()->where('action', 'cellar_activity.skipped')->exists()));
    }

    public function test_last_open_item_closes_the_round_card_and_moves_follow_the_card(): void
    {
        $res = null;
        $lot = $this->inTenant(function () use (&$vessel) {
            $vessel = $this->vessel('F11');

            return $this->lotIn('LOT-2026-001', '500.000', $vessel);
        });

        $res = $this->api('POST', 'cellar/activities', [
            'round_name' => 'Pump-overs',
            'activities' => [
                $this->item('PROCESS', $lot, $vessel, ['kind' => 'Pump-over'], '2026-10-14'),
                $this->item('PROCESS', $lot, $vessel, ['kind' => 'Pump-over'], '2026-10-15'),
            ],
        ])->assertCreated();

        [$first, $second] = $this->inTenant(fn () => CellarActivity::query()->orderBy('planned_for')->get()->all());
        $card = $this->inTenant(fn () => WorkOrder::query()->findOrFail((string) $res->json('data.work_order_id')));

        $this->api('POST', "cellar/activities/{$first->getKey()}/complete")->assertOk();
        $this->inTenant(fn () => $this->assertSame('2026-10-15', $card->refresh()->due_date?->toDateString()));

        $this->api('POST', "cellar/activity-rounds/{$res->json('data.round_id')}/reschedule", ['planned_for' => '2026-10-20', 'reason' => 'No time'])
            ->assertOk()->assertJsonPath('data.moved', 1);
        $this->inTenant(function () use ($card, $second): void {
            $this->assertSame('2026-10-20', $card->refresh()->due_date?->toDateString());
            $this->assertStringContainsString('⏭ No time', (string) $second->refresh()->note);
        });

        $this->api('POST', "cellar/activities/{$second->getKey()}/skip", ['reason' => 'other'])->assertOk();
        $this->inTenant(fn () => $this->assertSame(TaskStatus::Done, $card->refresh()->status));
    }

    public function test_delete_rules(): void
    {
        $lot = $this->inTenant(function () use (&$vessel) {
            $vessel = $this->vessel('F11');

            return $this->lotIn('LOT-2026-001', '500.000', $vessel);
        });

        $untouched = $this->api('POST', 'cellar/activities', [
            'round_name' => 'Untouched',
            'activities' => [$this->item('PROCESS', $lot, $vessel, ['kind' => 'Pump-over'])],
        ])->json('data');

        $this->api('DELETE', "cellar/activity-rounds/{$untouched['round_id']}")
            ->assertOk()->assertJsonPath('data.kept_history', false);
        $this->inTenant(function () use ($untouched): void {
            $this->assertNull(CellarActivityRound::query()->find($untouched['round_id']));
            $this->assertNull(WorkOrder::query()->find($untouched['work_order_id']));
        });

        $started = $this->api('POST', 'cellar/activities', [
            'round_name' => 'Started',
            'activities' => [
                $this->item('PROCESS', $lot, $vessel, ['kind' => 'Pump-over']),
                $this->item('PROCESS', $lot, $vessel, ['kind' => 'Punch-down']),
            ],
        ])->json('data');
        [$a, $b] = $this->inTenant(fn () => CellarActivity::query()->where('round_id', $started['round_id'])->orderBy('sort_order')->get()->all());

        $this->api('POST', "cellar/activities/{$a->getKey()}/complete")->assertOk();
        $this->api('DELETE', "cellar/activities/{$a->getKey()}")->assertUnprocessable();

        $this->api('DELETE', "cellar/activity-rounds/{$started['round_id']}")
            ->assertOk()->assertJsonPath('data.kept_history', true)->assertJsonPath('data.removed', 1);
        $this->inTenant(function () use ($started, $b): void {
            $this->assertNotNull(CellarActivityRound::query()->find($started['round_id']));
            $this->assertNull(CellarActivity::query()->find($b->getKey()));
            $this->assertSame(TaskStatus::Done, WorkOrder::query()->findOrFail((string) $started['work_order_id'])->status);
            $this->assertTrue(AuditLog::query()->where('action', 'cellar_activity_round.deleted')->exists());
        });
    }

    public function test_today_groups_by_room_filters_mine_and_reports_round_progress(): void
    {
        $other = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $lot = $this->inTenant(function () use (&$a1, &$b2) {
            $a1 = $this->vessel('A1', '1000.000', 'Barrique room');
            $b2 = $this->vessel('B2', '1000.000', 'Main Cellar');

            return $this->lotIn('LOT-2026-001', '1000.000', $a1, $b2);
        });

        $round = $this->api('POST', 'cellar/activities', [
            'round_name' => 'Morning',
            'activities' => [
                $this->item('PROCESS', $lot, $b2, ['kind' => 'Pump-over'], '2026-10-13'), // overdue
                $this->item('PROCESS', $lot, $a1, ['kind' => 'Pump-over'], '2026-10-14'),
                $this->item('PROCESS', $lot, $a1, ['kind' => 'Pump-over'], '2026-10-16'), // future, still counts in progress
            ],
        ])->json('data');
        $this->api('POST', 'cellar/activities', [
            'assigned_to_id' => $other->getKey(),
            'activities' => [$this->item('ANALYSIS', $lot, $b2, ['metrics' => ['free_so2']])],
        ])->assertCreated();

        $today = $this->api('GET', 'cellar/activities/today')->assertOk()->json('data');
        $this->assertSame('2026-10-14', $today['today']);
        $this->assertSame(['Barrique room', 'Main Cellar'], array_column($today['open'], 'room'));
        $this->assertSame(3, $today['counts']['open']);
        $this->assertSame(2, $today['counts']['mine']);
        $this->assertTrue($today['open'][1]['items'][0]['overdue']);
        $this->assertSame([['id' => $round['round_id'], 'name' => 'Morning', 'total' => 3, 'done' => 0, 'skipped' => 0]], $today['rounds']);

        $mine = $this->api('GET', 'cellar/activities/today?mine=1')->json('data');
        $this->assertSame(2, array_sum(array_map(fn ($g) => count($g['items']), $mine['open'])));

        $first = $today['open'][0]['items'][0]['id'];
        $this->api('POST', "cellar/activities/{$first}/complete")->assertOk();

        $after = $this->api('GET', 'cellar/activities/today')->json('data');
        $this->assertSame(1, $after['counts']['done_today']);
        $this->assertSame($first, $after['completed'][0]['id']);
        $this->assertSame(1, $after['rounds'][0]['done']);
    }

    public function test_plan_lists_open_work_by_day_and_recent_execution(): void
    {
        $lot = $this->inTenant(function () use (&$v) {
            $v = $this->vessel('F11');

            return $this->lotIn('LOT-2026-001', '500.000', $v);
        });

        $this->api('POST', 'cellar/activities', [
            'round_name' => 'Week',
            'activities' => [
                $this->item('PROCESS', $lot, $v, ['kind' => 'Pump-over'], '2026-10-14'),
                $this->item('PROCESS', $lot, $v, ['kind' => 'Pump-over'], '2026-10-15'),
            ],
        ])->assertCreated();
        $this->api('POST', 'cellar/activities', [
            'activities' => [$this->item('PROCESS', $lot, $v, ['kind' => 'Filter'], '2026-10-15')],
        ])->assertCreated();

        $first = $this->inTenant(fn () => CellarActivity::query()->whereDate('planned_for', '2026-10-14')->sole());
        $this->api('POST', "cellar/activities/{$first->getKey()}/complete")->assertOk();

        $plan = $this->api('GET', 'cellar/activities/plan')->assertOk()->json('data');
        $this->assertSame(['2026-10-15'], array_column($plan['open'], 'date'));
        $this->assertCount(1, $plan['open'][0]['rounds']);
        $this->assertCount(1, $plan['open'][0]['singles']);
        $this->assertSame('2026-10-14', $plan['executed'][0]['date']);
        $this->assertSame(1, $plan['executed'][0]['rounds'][0]['done']);
        $this->assertSame([$this->admin->fullName()], $plan['executed'][0]['rounds'][0]['executed_by']);
    }

    public function test_plan_protocol_additions_doses_by_basis(): void
    {
        [$lot, $big, $small] = $this->inTenant(function () {
            $big = $this->vessel('T1', '2000.000');
            $small = $this->vessel('T2', '2000.000');
            $lot = $this->lotIn('LOT-2026-001', '1000.000');
            VesselLot::create(['vessel_id' => $big->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => '750.000']);
            VesselLot::create(['vessel_id' => $small->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => '250.000']);
            $yeast = EnologicalProduct::create(['name' => 'Yeast RC212', 'category' => 'YEAST', 'unit' => 'g', 'current_stock' => '1000.000']);
            $template = FermentationTemplate::create(['name' => 'Red']);
            $template->additives()->create(['name' => 'Yeast', 'enological_product_id' => $yeast->getKey(), 'dose' => '20', 'basis' => 'per_hL', 'stage' => 'Inoculation']);
            $template->additives()->create(['name' => 'Tannin', 'dose' => '0.5', 'basis' => 'per_100kg']);
            $lot->forceFill(['fermentation_template_id' => $template->getKey(), 'harvest_weight_kg' => '1500.000'])->save();

            return [$lot, $big, $small];
        });

        $res = $this->api('POST', "wine-lots/{$lot->getKey()}/protocol/plan-additions", ['planned_for' => '2026-10-14'])
            ->assertCreated()
            ->assertJsonPath('data.count', 4)
            ->assertJsonPath('data.skipped_no_grape_weight', []);

        $this->inTenant(function () use ($big, $small, $res): void {
            $q = fn (string $vessel, string $name) => CellarActivity::query()->where('vessel_id', $vessel)->get()
                ->first(fn (CellarActivity $a) => $a->payload['product_name'] === $name)?->payload['quantity'];

            $this->assertEquals(150, $q($big->getKey(), 'Yeast RC212'));   // 20 g/hL × 7.5 hL
            $this->assertEquals(50, $q($small->getKey(), 'Yeast RC212'));  // 20 g/hL × 2.5 hL
            $this->assertEquals(5.63, $q($big->getKey(), 'Tannin'));       // 0.5 × (1500 kg × 75%) / 100
            $this->assertEquals(1.88, $q($small->getKey(), 'Tannin'));
            $this->assertSame('Protocol Red — Lot LOT-2026-001 2026', CellarActivityRound::query()->findOrFail((string) $res->json('data.round_id'))->name);
        });
    }

    public function test_plan_protocol_additions_skips_weight_based_doses_without_grape_weight(): void
    {
        $lot = $this->inTenant(function () {
            $v = $this->vessel('T1');
            $lot = $this->lotIn('LOT-2026-001', '500.000', $v);
            $template = FermentationTemplate::create(['name' => 'Red']);
            $template->additives()->create(['name' => 'Nutrient', 'dose' => '0.3', 'basis' => 'per_L']);
            $template->additives()->create(['name' => 'Tannin', 'dose' => '0.5', 'basis' => 'per_kg']);
            $lot->forceFill(['fermentation_template_id' => $template->getKey()])->save();

            return $lot;
        });

        $this->api('POST', "wine-lots/{$lot->getKey()}/protocol/plan-additions", ['planned_for' => '2026-10-14'])
            ->assertCreated()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.skipped_no_grape_weight', ['Tannin']);
    }

    public function test_so2_round_plans_one_addition_per_vessel(): void
    {
        [$lot, $v1, $v2, $product] = $this->inTenant(function () {
            $v1 = $this->vessel('F11');
            $v2 = $this->vessel('F12');
            $product = EnologicalProduct::create(['name' => 'K2S2O5', 'category' => 'SO2', 'unit' => 'g', 'current_stock' => '1000.000']);

            return [$this->lotIn('LOT-2026-001', '1000.000', $v1, $v2), $v1, $v2, $product];
        });

        $this->api('POST', 'cellar/so2-rounds', [
            'planned_for' => '2026-10-14',
            'enological_product_id' => $product->getKey(),
            'product_name' => 'K2S2O5',
            'unit' => 'g',
            'items' => [
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $v1->getKey(), 'quantity' => 7.456, 'target_note' => '+10 mg/L (18 → 28)'],
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $v2->getKey(), 'quantity' => 5],
            ],
        ])->assertCreated()->assertJsonPath('data.count', 2);

        $this->inTenant(function () use ($v1): void {
            $this->assertSame('SO₂ round', CellarActivityRound::query()->sole()->name);
            $a = CellarActivity::query()->where('vessel_id', $v1->getKey())->sole();
            $this->assertEquals(7.46, $a->payload['quantity']);
            $this->assertSame('SO2', $a->payload['category']);
        });
    }

    public function test_lot_detail_lists_planned_additions(): void
    {
        $lot = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $lot = $this->lotIn('LOT-2026-001', '500.000', $v);
            CellarActivity::create([
                'type' => 'ADDITION', 'planned_for' => '2026-10-15', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(),
                'planned_by_id' => $this->admin->getKey(),
                'payload' => ['product_name' => 'Bentonite', 'unit' => 'g', 'quantity' => 40],
            ]);

            return $lot;
        });

        $this->api('GET', "wine-lots/{$lot->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.planned_additions.0.product_name', 'Bentonite')
            ->assertJsonPath('data.planned_additions.0.vessel.name', 'F11');
    }

    public function test_cellar_role_can_plan_and_execute_but_others_cannot(): void
    {
        $cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $orders = $this->createMember($this->tenant, [TenantRole::Orders]);
        $lot = $this->inTenant(function () use (&$v) {
            $v = $this->vessel('F11');

            return $this->lotIn('LOT-2026-001', '500.000', $v);
        });
        $body = ['activities' => [$this->item('PROCESS', $lot, $v, ['kind' => 'Pump-over'])]];

        $this->api('POST', 'cellar/activities', $body, $cellar)->assertCreated();
        $this->api('GET', 'cellar/activities/today', [], $cellar)->assertOk();
        $this->api('POST', 'cellar/activities', $body, $orders)->assertForbidden();
        $this->api('GET', 'cellar/activities/today', [], $orders)->assertForbidden();
    }

    public function test_activities_are_tenant_isolated(): void
    {
        $activity = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $lot = $this->lotIn('LOT-2026-001', '500.000', $v);

            return CellarActivity::create([
                'type' => 'PROCESS', 'planned_for' => '2026-10-14', 'wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(),
                'planned_by_id' => $this->admin->getKey(), 'payload' => ['kind' => 'Pump-over'],
            ]);
        });

        $otherTenant = $this->createTenant();
        $intruder = $this->createMember($otherTenant, [TenantRole::Admin]);
        Sanctum::actingAs($intruder);

        $this->postJson("/api/v1/cellar/activities/{$activity->getKey()}/complete", [], $this->tenantHeader($otherTenant))
            ->assertNotFound();
    }
}
