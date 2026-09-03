<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\NotificationType;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Order;
use App\Services\Auth\ActiveTenantSession;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The @-mention picker's member list, and the web comment route actually
 * threading `mentions` through to a notification. Notifier::orderComment()'s
 * Mention-vs-Reply split is already covered end to end in
 * tests/Feature/Orders/NotificationTest.php — this just proves the web path
 * carries `mentions` at all, which the old plain `<input>` never did.
 */
class MentionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_team_members_lists_only_the_current_tenants_members(): void
    {
        $tenant = $this->createTenant();
        $viewer = $this->createMember($tenant, [TenantRole::Orders]);
        $this->createMember($tenant, [TenantRole::Team]);

        $foreign = $this->createTenant();
        $this->createMember($foreign, [TenantRole::Admin]);

        $this->actingAs($viewer)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->getJson('/team-members')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'email']]]);
    }

    public function test_mentioning_a_member_in_a_comment_notifies_them(): void
    {
        $tenant = $this->createTenant();
        $author = $this->createMember($tenant, [TenantRole::Orders]);
        $mentioned = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $order = Order::create([
            'order_number' => 'MT-1', 'customer_id' => Customer::create(['company_name' => 'K', 'email' => 'k@example.com'])->getKey(),
            'created_by_id' => $author->getKey(), 'total_amount' => Money::fromMinor(1000, 'EUR'),
        ]);
        $this->forgetTenant();

        $this->actingAs($author)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post("/orders/{$order->getKey()}/comments", [
                'content' => 'Can you check this?',
                'mentions' => [$mentioned->getKey()],
            ])->assertRedirect();

        $this->actingAsTenant($tenant);
        $notification = Notification::query()->where('user_id', $mentioned->getKey())->firstOrFail();
        $this->assertSame(NotificationType::Mention, $notification->type);
        $this->forgetTenant();
    }
}
