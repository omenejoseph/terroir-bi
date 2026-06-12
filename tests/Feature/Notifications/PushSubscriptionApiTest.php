<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\TenantRole;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PushSubscriptionApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->user = $this->createMember($this->tenant, [TenantRole::Team]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    /** @return array<string, mixed> */
    private function payload(string $endpoint = 'https://push.example/abc'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'BPublicKeyXYZ', 'auth' => 'authSecret'],
            'ua' => 'Firefox',
        ];
    }

    public function test_it_registers_a_subscription_for_the_user(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/push-subscriptions', $this->payload(), $this->headers())
            ->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->user->getKey(),
            'endpoint' => 'https://push.example/abc',
            'p256dh' => 'BPublicKeyXYZ',
        ]);
    }

    public function test_registering_the_same_endpoint_upserts_rather_than_duplicates(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/push-subscriptions', $this->payload(), $this->headers())->assertCreated();
        $this->postJson('/api/v1/push-subscriptions', [
            ...$this->payload(),
            'keys' => ['p256dh' => 'RotatedKey', 'auth' => 'rotatedAuth'],
        ], $this->headers())->assertCreated();

        $this->assertSame(1, PushSubscription::query()->where('endpoint', 'https://push.example/abc')->count());
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push.example/abc', 'p256dh' => 'RotatedKey']);
    }

    public function test_it_validates_the_subscription_shape(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/push-subscriptions', ['endpoint' => 'https://push.example/x'], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keys']);
    }

    public function test_it_unregisters_a_subscription(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/push-subscriptions', $this->payload(), $this->headers())->assertCreated();

        $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => 'https://push.example/abc'], $this->headers())
            ->assertNoContent();

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://push.example/abc']);
    }

    public function test_a_user_cannot_delete_another_users_subscription(): void
    {
        $other = $this->createMember($this->tenant, [TenantRole::Team]);
        PushSubscription::create([
            'user_id' => $other->getKey(),
            'endpoint' => 'https://push.example/other',
            'p256dh' => 'k', 'auth' => 'a',
        ]);

        Sanctum::actingAs($this->user);
        $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => 'https://push.example/other'], $this->headers())
            ->assertNoContent();

        // Still there — it belonged to someone else.
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push.example/other']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/push-subscriptions', $this->payload())->assertUnauthorized();
    }
}
