<?php

declare(strict_types=1);

namespace Tests\Feature\Kitchen;

use App\Actions\Kitchen\CompleteKitchenCheckAction;
use App\Actions\Kitchen\RecordKitchenCountAction;
use App\Actions\Kitchen\SaveKitchenItemAction;
use App\Actions\Kitchen\StartKitchenCheckAction;
use App\Enums\KitchenCheckStatus;
use App\Enums\KitchenLocation;
use App\Enums\KitchenMovementType;
use App\Enums\TenantRole;
use App\Models\KitchenCheckItem;
use App\Models\KitchenItem;
use App\Models\KitchenMovement;
use App\Models\KitchenWorkOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Kitchen\KitchenStockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** The kitchen's stock rules, stock-take and prep tickets (docs/21 §3), and who may reach them. */
class KitchenTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->cook = $this->createMember($this->tenant, [TenantRole::Kitchen]);
        $this->actingAsTenant($this->tenant);
    }

    private function item(string $name = 'Flour', string $opening = '10', KitchenLocation $location = KitchenLocation::Restaurant): KitchenItem
    {
        return app(SaveKitchenItemAction::class)->execute([
            'name' => $name,
            'category' => 'RAW_MATERIAL',
            'unit' => 'kg',
            'location' => $location->value,
            'opening_stock' => $opening,
        ], $this->cook->getKey());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function hit(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        $this->forgetTenant();

        return $this->actingAs($user)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])->{$method}($uri, $data);
    }

    public function test_opening_stock_is_booked_as_a_movement_so_history_adds_up(): void
    {
        $item = $this->item(opening: '12.5');

        $this->assertSame('12.500', (string) $item->refresh()->current_stock);
        $movement = KitchenMovement::query()->where('item_id', $item->getKey())->sole();
        $this->assertSame(KitchenMovementType::In, $movement->type);
        $this->assertSame('12.500', (string) $movement->quantity);
    }

    public function test_in_out_and_adjustment_store_the_signed_change_and_out_may_go_negative(): void
    {
        $item = $this->item(opening: '10');
        $ledger = app(KitchenStockLedger::class);

        $ledger->move($item, KitchenMovementType::In, '2.5', null, $this->cook->getKey());
        $out = $ledger->move($item, KitchenMovementType::Out, '15', null, $this->cook->getKey());
        $this->assertSame('-2.500', (string) $item->refresh()->current_stock);
        $this->assertSame('-15.000', (string) $out->quantity);

        $adjust = $ledger->move($item, KitchenMovementType::Adjustment, '4', 'Counted', $this->cook->getKey());
        $this->assertSame('4.000', (string) $item->refresh()->current_stock);
        $this->assertSame('6.500', (string) $adjust->quantity);

        $this->assertSame('4.000', number_format((float) KitchenMovement::query()->where('item_id', $item->getKey())->sum('quantity'), 3, '.', ''));
    }

    public function test_a_stock_take_snapshots_one_location_and_completing_it_adjusts_only_counted_differences(): void
    {
        $flour = $this->item('Flour', '10');
        $sugar = $this->item('Sugar', '5');
        $salt = $this->item('Salt', '3');
        $this->item('Wine glasses', '40', KitchenLocation::TastingRoom);

        $check = app(StartKitchenCheckAction::class)->execute('2026-07-01', KitchenLocation::Restaurant, null, $this->cook->getKey());
        $this->assertSame(3, $check->items()->count());

        $line = fn (KitchenItem $i): KitchenCheckItem => $check->items()->where('item_id', $i->getKey())->sole();
        app(RecordKitchenCountAction::class)->execute($line($flour), '8');
        app(RecordKitchenCountAction::class)->execute($line($sugar), '5');
        // Salt is not counted.
        $this->assertSame('-2.000', (string) $line($flour)->difference);

        app(CompleteKitchenCheckAction::class)->execute($check, $this->cook->getKey());

        $this->assertSame(KitchenCheckStatus::Completed, $check->refresh()->status);
        $this->assertSame('8.000', (string) $flour->refresh()->current_stock);
        $this->assertSame('5.000', (string) $sugar->refresh()->current_stock);
        $this->assertSame('3.000', (string) $salt->refresh()->current_stock);
        // Only flour differed.
        $this->assertSame(1, KitchenMovement::query()->where('type', KitchenMovementType::Adjustment)->count());
    }

    public function test_prep_tickets_replace_their_lines_and_never_touch_stock(): void
    {
        $flour = $this->item('Flour', '10');

        $this->hit($this->cook, 'post', '/kitchen/work-orders', [
            'title' => 'Saturday lunch',
            'date' => '2026-07-04',
            'assigned_to' => 'Marko',
            'items' => [
                ['item_id' => $flour->getKey(), 'quantity' => 2],
                ['custom_name' => 'Staff meal', 'quantity' => 6, 'unit' => 'portions'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAsTenant($this->tenant);
        $order = KitchenWorkOrder::query()->sole();
        $this->assertSame(2, $order->items()->count());

        $this->hit($this->cook, 'patch', '/kitchen/work-orders/'.$order->getKey(), [
            'title' => 'Saturday lunch',
            'date' => '2026-07-04',
            'items' => [['custom_name' => 'Bread', 'quantity' => 3]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->hit($this->cook, 'patch', '/kitchen/work-orders/'.$order->getKey().'/status', ['status' => 'COMPLETED'])->assertRedirect();

        $this->actingAsTenant($this->tenant);
        $order->refresh();
        $this->assertSame(['Bread'], $order->items()->pluck('custom_name')->all());
        $this->assertNotNull($order->completed_at);
        $this->assertSame('10.000', (string) $flour->refresh()->current_stock);
    }

    public function test_a_line_needs_an_item_or_a_name(): void
    {
        $this->hit($this->cook, 'post', '/kitchen/work-orders', [
            'title' => 'X',
            'date' => '2026-07-04',
            'items' => [['quantity' => 1]],
        ])->assertSessionHasErrors('items.0.custom_name');
    }

    public function test_the_page_and_its_writes_are_kitchen_and_admin_only(): void
    {
        $item = $this->item();
        $orders = $this->createMember($this->tenant, [TenantRole::Orders]);
        $admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->hit($this->cook, 'get', '/kitchen')->assertOk();
        $this->hit($admin, 'get', '/kitchen')->assertOk();
        $this->hit($orders, 'get', '/kitchen')->assertForbidden();
        $this->hit($orders, 'post', '/kitchen/items/'.$item->getKey().'/stock', ['type' => 'IN', 'quantity' => 1])->assertForbidden();

        $this->hit($this->cook, 'post', '/kitchen/items/'.$item->getKey().'/stock', ['type' => 'OUT', 'quantity' => 4, 'note' => 'Bread'])->assertRedirect();
        $this->actingAsTenant($this->tenant);
        $this->assertSame('6.000', (string) $item->refresh()->current_stock);
    }

    public function test_a_completed_stock_take_cannot_be_deleted_or_counted_again(): void
    {
        $this->item();
        $check = app(StartKitchenCheckAction::class)->execute('2026-07-01', null, null, $this->cook->getKey());
        app(CompleteKitchenCheckAction::class)->execute($check, $this->cook->getKey());
        $line = $check->items()->firstOrFail();

        $this->hit($this->cook, 'delete', '/kitchen/checks/'.$check->getKey())->assertRedirect()->assertSessionHas('error');
        $this->hit($this->cook, 'patch', '/kitchen/checks/'.$check->getKey().'/lines/'.$line->getKey(), ['physical_count' => 1])
            ->assertSessionHasErrors('physical_count');
    }
}
