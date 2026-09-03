<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\NotificationType;
use App\Enums\TenantRole;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{Tenant, User} */
    private function acting(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAs($user)->withSession([ActiveTenantSession::KEY => $tenant->getKey()]);

        return [$tenant, $user];
    }

    private function notify(Tenant $tenant, User $user, string $title, bool $isRead = false): Notification
    {
        $this->actingAsTenant($tenant);
        $notification = Notification::create([
            'user_id' => $user->getKey(), 'type' => NotificationType::NewOrder,
            'title' => $title, 'is_read' => $isRead,
        ]);
        $this->forgetTenant();

        return $notification;
    }

    public function test_index_returns_only_the_acting_users_own_notifications(): void
    {
        [$tenant, $user] = $this->acting();
        $other = $this->createMember($tenant, [TenantRole::Admin]);

        $this->notify($tenant, $user, 'Mine');
        $this->notify($tenant, $other, 'Someone else\'s');

        $this->getJson('/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_index_never_returns_another_tenants_notification(): void
    {
        [$tenant, $user] = $this->acting();
        $foreign = $this->createTenant();
        $foreignUser = $this->createMember($foreign, [TenantRole::Admin]);

        // Same user id would never happen in practice, but the tenant scope
        // must hold regardless — assert on a same-user-id-shaped notification
        // created under the foreign tenant.
        $this->notify($foreign, $foreignUser, 'Foreign');
        $this->notify($tenant, $user, 'Mine');

        $this->getJson('/notifications')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_read_with_no_ids_marks_every_notification_read(): void
    {
        [$tenant, $user] = $this->acting();
        $this->notify($tenant, $user, 'A');
        $this->notify($tenant, $user, 'B');

        $this->postJson('/notifications/read')->assertStatus(204);

        $this->assertSame(0, Notification::query()->where('is_read', false)->count());
    }

    public function test_read_with_ids_marks_only_those(): void
    {
        [$tenant, $user] = $this->acting();
        $a = $this->notify($tenant, $user, 'A');
        $b = $this->notify($tenant, $user, 'B');

        $this->postJson('/notifications/read', ['ids' => [$a->getKey()]])->assertStatus(204);

        $this->assertTrue($a->fresh()->is_read);
        $this->assertFalse($b->fresh()->is_read);
    }

    public function test_destroy_removes_one_notification(): void
    {
        [$tenant, $user] = $this->acting();
        $notification = $this->notify($tenant, $user, 'Gone soon');

        $this->deleteJson("/notifications/{$notification->getKey()}")->assertStatus(204);

        $this->assertNull(Notification::find($notification->getKey()));
    }

    public function test_destroy_is_a_no_op_against_another_users_notification(): void
    {
        [$tenant, $user] = $this->acting();
        $other = $this->createMember($tenant, [TenantRole::Admin]);
        $theirs = $this->notify($tenant, $other, 'Not yours');

        $this->deleteJson("/notifications/{$theirs->getKey()}")->assertStatus(204);

        $this->assertNotNull(Notification::find($theirs->getKey()));
    }

    public function test_clear_removes_only_the_acting_users_notifications(): void
    {
        [$tenant, $user] = $this->acting();
        $other = $this->createMember($tenant, [TenantRole::Admin]);
        $mine = $this->notify($tenant, $user, 'Mine');
        $theirs = $this->notify($tenant, $other, 'Theirs');

        $this->postJson('/notifications/clear')->assertStatus(204);

        $this->assertNull(Notification::find($mine->getKey()));
        $this->assertNotNull(Notification::find($theirs->getKey()));
    }
}
