<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\DataTransferObjects\PushMessageData;
use App\Enums\NotificationType;
use App\Enums\TenantRole;
use App\Jobs\SendWebPushNotification;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Support\FakeWebPushSender;
use Tests\TestCase;

class PushDeliveryTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $teammate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->teammate = $this->createMember($this->tenant, [TenantRole::Team]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_creating_an_order_queues_a_push_per_recipient_with_type_and_data(): void
    {
        Queue::fake();

        $this->actingAsTenant($this->tenant);
        $customer = Customer::create(['company_name' => 'Bar', 'email' => 'b@example.com']);
        $wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '100.000', 'is_for_sale' => true, 'default_price' => 1000,
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $orderId = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->getKey(),
            'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
        ], $this->headers())->assertCreated()->json('data.id');

        // admin + teammate are both order-role recipients.
        Queue::assertPushed(SendWebPushNotification::class, 2);
        Queue::assertPushed(SendWebPushNotification::class, function (SendWebPushNotification $job) use ($orderId): bool {
            return $job->message->type === NotificationType::NewOrder
                && ($job->message->data['order_id'] ?? null) === $orderId;
        });
    }

    public function test_sender_delivers_to_every_device_and_prunes_expired(): void
    {
        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        PushSubscription::create(['user_id' => $this->teammate->getKey(), 'endpoint' => 'https://push/live', 'p256dh' => 'k', 'auth' => 'a']);
        PushSubscription::create(['user_id' => $this->teammate->getKey(), 'endpoint' => 'https://push/dead', 'p256dh' => 'k', 'auth' => 'a']);

        $fake->expiredEndpoints = ['https://push/dead'];

        app(WebPushSender::class)->sendToUser(
            $this->teammate->getKey(),
            new PushMessageData('Hi', null, NotificationType::Announcement),
        );

        $this->assertCount(1, $fake->sent);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push/live']);
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://push/dead']);
    }
}
