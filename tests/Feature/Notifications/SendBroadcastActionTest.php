<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\SendBroadcastAction;
use App\Enums\MembershipStatus;
use App\Enums\NotificationType;
use App\Enums\TenantRole;
use App\Jobs\SendWebPushNotification;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class SendBroadcastActionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantA = $this->createTenant();
        $this->tenantB = $this->createTenant();
    }

    private function rowsFor(Tenant $tenant, string $userId): int
    {
        return Notification::withoutTenant()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $userId)
            ->where('type', NotificationType::Announcement->value)
            ->count();
    }

    public function test_broadcast_to_all_writes_feed_rows_and_pushes_once_per_user(): void
    {
        Queue::fake();

        $alice = $this->createMember($this->tenantA, [TenantRole::Admin]);
        $bob = $this->createMember($this->tenantB, [TenantRole::Team]);
        // Same user is a member of both tenants → two feed rows, one push.
        $this->createMembershipFor($alice, $this->tenantB);

        $result = app(SendBroadcastAction::class)->execute('Maintenance tonight', 'Back at 22:00', null);

        $this->assertSame(2, $result['tenants']);
        $this->assertSame(2, $result['recipients']);   // alice + bob (deduped)
        $this->assertSame(3, $result['notifications']); // alice×2 tenants + bob×1

        $this->assertSame(1, $this->rowsFor($this->tenantA, $alice->getKey()));
        $this->assertSame(1, $this->rowsFor($this->tenantB, $alice->getKey()));
        $this->assertSame(1, $this->rowsFor($this->tenantB, $bob->getKey()));

        // One push per distinct user, not per feed row.
        Queue::assertPushed(SendWebPushNotification::class, 2);
    }

    public function test_broadcast_to_specific_tenant_only_targets_its_members(): void
    {
        Queue::fake();

        $alice = $this->createMember($this->tenantA, [TenantRole::Admin]);
        $bob = $this->createMember($this->tenantB, [TenantRole::Team]);

        $result = app(SendBroadcastAction::class)->execute('Hello A', null, [$this->tenantA->getKey()]);

        $this->assertSame(1, $result['recipients']);
        $this->assertSame(1, $this->rowsFor($this->tenantA, $alice->getKey()));
        $this->assertSame(0, $this->rowsFor($this->tenantB, $bob->getKey()));
        Queue::assertPushed(SendWebPushNotification::class, 1);
    }

    public function test_inactive_members_are_skipped(): void
    {
        Queue::fake();

        $this->createMember($this->tenantA, [TenantRole::Team], status: MembershipStatus::Suspended);

        $result = app(SendBroadcastAction::class)->execute('Nobody home', null, [$this->tenantA->getKey()]);

        $this->assertSame(0, $result['recipients']);
        Queue::assertNotPushed(SendWebPushNotification::class);
    }
}
