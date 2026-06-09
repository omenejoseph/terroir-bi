<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $teammate;

    private Customer $customer;

    private InventoryItem $wine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->teammate = $this->createMember($this->tenant, [TenantRole::Team]);
        $this->actingAsTenant($this->tenant);
        $this->customer = Customer::create(['company_name' => 'Bar', 'email' => 'b@example.com']);
        $this->wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '100.000', 'is_for_sale' => true, 'default_price' => 1000,
        ]);
        $this->forgetTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function createOrder(): string
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
        ], $this->headers())->assertCreated()->json('data.id');
    }

    public function test_new_order_notifies_order_role_members(): void
    {
        $this->createOrder();

        Sanctum::actingAs($this->teammate);
        $this->getJson('/api/v1/notifications', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.type', 'NEW_ORDER');
    }

    public function test_status_change_notifies_followers_not_the_actor(): void
    {
        $id = $this->createOrder();

        // Teammate moves it forward; the creator (admin) is a follower and is notified.
        Sanctum::actingAs($this->teammate);
        $this->patchJson("/api/v1/orders/{$id}/status", ['status' => 'IN_PROCESS'], $this->headers())->assertOk();

        Sanctum::actingAs($this->admin);
        $types = array_column((array) $this->getJson('/api/v1/notifications', $this->headers())->json('data'), 'type');
        $this->assertContains('ORDER_STATUS', $types);
    }

    public function test_comment_mention_and_reply_notifications(): void
    {
        $id = $this->createOrder();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/orders/{$id}/comments", [
            'content' => 'Can you check stock @team?',
            'mentions' => [$this->teammate->getKey()],
        ], $this->headers())->assertCreated();

        Sanctum::actingAs($this->teammate);
        $this->getJson('/api/v1/notifications?unread=1', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.type', 'MENTION');
    }

    public function test_marking_notifications_read(): void
    {
        $this->createOrder();

        Sanctum::actingAs($this->teammate);
        $this->getJson('/api/v1/notifications?unread=1', $this->headers())->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/notifications/read', [], $this->headers())->assertNoContent();
        $this->getJson('/api/v1/notifications?unread=1', $this->headers())->assertJsonCount(0, 'data');
    }

    public function test_only_author_or_admin_can_edit_a_comment(): void
    {
        $id = $this->createOrder();
        Sanctum::actingAs($this->admin);
        $commentId = $this->postJson("/api/v1/orders/{$id}/comments", ['content' => 'mine'], $this->headers())
            ->assertCreated()->json('data.id');

        // A different non-admin member cannot edit it.
        $other = $this->createMember($this->tenant, [TenantRole::Team]);
        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/order-comments/{$commentId}", ['content' => 'hacked'], $this->headers())
            ->assertStatus(403);

        // The author can.
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/order-comments/{$commentId}", ['content' => 'edited'], $this->headers())
            ->assertOk()->assertJsonPath('data.content', 'edited');
    }

    public function test_stale_order_command_flags_and_notifies(): void
    {
        $id = $this->createOrder();
        // Age the order beyond 24h.
        $this->actingAsTenant($this->tenant);
        Order::query()->whereKey($id)->update(['updated_at' => now()->subDays(2)]);
        $this->forgetTenant();

        $this->assertSame(0, Artisan::call('orders:stale'));

        $this->actingAsTenant($this->tenant);
        $this->assertNotNull(Order::query()->whereKey($id)->firstOrFail()->last_stale_notified_at);
        $this->forgetTenant();
    }
}
