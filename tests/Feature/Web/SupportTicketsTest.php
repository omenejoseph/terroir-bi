<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Enums\NotificationType;
use App\Enums\SupportTicketStatus;
use App\Enums\TenantRole;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewSupportTicketNotification;
use App\Notifications\SupportTicketReplyNotification;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Help & support: members raise tickets from their tenant (/support), the
 * platform team works them from /admin/support.
 */
class SupportTicketsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        return app(SetPlatformAdminAction::class)->execute(User::factory()->create(), true);
    }

    private function asMember(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession([ActiveTenantSession::KEY => $tenant->getKey()]);
    }

    private function raise(User $user, Tenant $tenant, string $subject = 'Import skips rows'): SupportTicket
    {
        $this->asMember($user, $tenant)->post('/support', [
            'subject' => $subject,
            'category' => 'problem',
            'body' => 'Rows 4 and 9 never arrive.',
            'page_url' => '/inventory',
        ])->assertRedirect();

        return SupportTicket::query()->where('subject', $subject)->firstOrFail();
    }

    public function test_a_member_raises_a_ticket_and_platform_admins_are_emailed(): void
    {
        NotificationFacade::fake();
        $admin = $this->platformAdmin();
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $ticket = $this->raise($member, $tenant);

        $this->assertSame(1001, $ticket->number);
        $this->assertSame($tenant->getKey(), $ticket->tenant_id);
        $this->assertSame(SupportTicketStatus::Open, $ticket->status);
        $this->assertSame('/inventory', $ticket->page_url);
        $this->assertSame(1, $ticket->messages()->count());
        NotificationFacade::assertSentTo($admin, NewSupportTicketNotification::class);

        $this->asMember($member, $tenant)
            ->get('/support/'.$ticket->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Support/Show')
                ->where('ticket.number', 1001)
                ->has('ticket.messages', 1));
    }

    public function test_members_see_their_own_tickets_and_org_admins_see_all(): void
    {
        NotificationFacade::fake();
        $tenant = $this->createTenant();
        $alice = $this->createMember($tenant, [TenantRole::Team]);
        $bob = $this->createMember($tenant, [TenantRole::Team]);
        $orgAdmin = $this->createMember($tenant, [TenantRole::Admin]);

        $alices = $this->raise($alice, $tenant, 'Alice asks');
        $this->raise($bob, $tenant, 'Bob asks');

        $this->asMember($alice, $tenant)->get('/support')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tickets', 1)->where('tickets.0.subject', 'Alice asks'));
        $this->asMember($bob, $tenant)->get('/support/'.$alices->getKey())->assertNotFound();

        $this->asMember($orgAdmin, $tenant)->get('/support')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tickets', 2)->where('seesAll', true));
    }

    public function test_another_tenants_ticket_is_not_found(): void
    {
        NotificationFacade::fake();
        $tenant = $this->createTenant();
        $other = $this->createTenant();
        $theirs = $this->raise($this->createMember($other, [TenantRole::Admin]), $other);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->asMember($admin, $tenant)->get('/support/'.$theirs->getKey())->assertNotFound();
        $this->asMember($admin, $tenant)->post('/support/'.$theirs->getKey().'/replies', ['body' => 'hi'])->assertNotFound();
    }

    public function test_a_staff_reply_waits_on_the_customer_and_tells_them(): void
    {
        NotificationFacade::fake();
        $staff = $this->platformAdmin();
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);
        $ticket = $this->raise($member, $tenant);

        $this->actingAs($staff)
            ->post('/admin/support/'.$ticket->getKey().'/replies', ['body' => 'Fixed in the latest release.'])
            ->assertRedirect();

        $this->assertSame(SupportTicketStatus::WaitingOnCustomer, $ticket->fresh()?->status);
        NotificationFacade::assertSentTo($member, SupportTicketReplyNotification::class);

        $this->actingAsTenant($tenant);
        $bell = Notification::query()->where('user_id', $member->getKey())->firstOrFail();
        $this->assertSame(NotificationType::SupportReply, $bell->type);
        $this->assertSame((string) $ticket->getKey(), $bell->data['ticket_id'] ?? null);
        $this->forgetTenant();

        // The customer's answer puts it back in the queue.
        $this->asMember($member, $tenant)
            ->post('/support/'.$ticket->getKey().'/replies', ['body' => 'Works now, thanks.'])
            ->assertRedirect();

        $this->assertSame(SupportTicketStatus::Open, $ticket->fresh()?->status);
    }

    public function test_internal_notes_never_reach_the_tenant(): void
    {
        NotificationFacade::fake();
        $staff = $this->platformAdmin();
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);
        $ticket = $this->raise($member, $tenant);

        $this->actingAs($staff)
            ->post('/admin/support/'.$ticket->getKey().'/replies', ['body' => 'Probably the CSV encoding.', 'internal' => true])
            ->assertRedirect();

        $this->assertSame(SupportTicketStatus::Open, $ticket->fresh()?->status);
        NotificationFacade::assertNotSentTo($member, SupportTicketReplyNotification::class);

        $this->asMember($member, $tenant)
            ->get('/support/'.$ticket->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page->has('ticket.messages', 1));

        $this->actingAs($staff)
            ->get('/admin/support/'.$ticket->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ticket.messages', 2)
                ->where('ticket.messages.1.is_internal', true));
    }

    public function test_the_requester_can_resolve_and_a_reply_reopens(): void
    {
        NotificationFacade::fake();
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);
        $ticket = $this->raise($member, $tenant);

        $this->asMember($member, $tenant)->post('/support/'.$ticket->getKey().'/resolve')->assertRedirect();
        $this->assertSame(SupportTicketStatus::Resolved, $ticket->fresh()?->status);
        $this->assertNotNull($ticket->fresh()?->resolved_at);

        $this->asMember($member, $tenant)->post('/support/'.$ticket->getKey().'/replies', ['body' => 'It came back.']);
        $this->assertSame(SupportTicketStatus::Open, $ticket->fresh()?->status);
        $this->assertNull($ticket->fresh()?->resolved_at);
    }

    public function test_a_closed_ticket_takes_no_replies(): void
    {
        NotificationFacade::fake();
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);
        $ticket = $this->raise($member, $tenant);
        $ticket->update(['status' => SupportTicketStatus::Closed]);

        $this->asMember($member, $tenant)
            ->post('/support/'.$ticket->getKey().'/replies', ['body' => 'Hello?'])
            ->assertStatus(422);
    }

    public function test_the_admin_queue_spans_tenants_and_triage_saves(): void
    {
        NotificationFacade::fake();
        $staff = $this->platformAdmin();
        $a = $this->createTenant(['name' => 'Vinarija A']);
        $b = $this->createTenant(['name' => 'Vinarija B']);
        $first = $this->raise($this->createMember($a, [TenantRole::Team]), $a, 'From A');
        $this->raise($this->createMember($b, [TenantRole::Team]), $b, 'From B');

        $this->actingAs($staff)
            ->get('/admin/support')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Support/Index')
                ->has('tickets.data', 2)
                ->where('counts.active', 2)
                ->where('counts.unassigned', 2));

        $this->actingAs($staff)
            ->patch('/admin/support/'.$first->getKey(), ['status' => 'in_progress', 'priority' => 'urgent', 'assignee_id' => $staff->getKey()])
            ->assertRedirect();

        $fresh = $first->fresh();
        $this->assertSame(SupportTicketStatus::InProgress, $fresh?->status);
        $this->assertSame('urgent', $fresh?->priority->value);
        $this->assertSame($staff->getKey(), $fresh?->assignee_id);

        $this->actingAs($staff)
            ->get('/admin/support?search=Vinarija+B')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tickets.data', 1)->where('tickets.data.0.subject', 'From B'));
    }

    public function test_only_platform_admins_can_be_assigned_or_reach_the_queue(): void
    {
        NotificationFacade::fake();
        $staff = $this->platformAdmin();
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Admin]);
        $ticket = $this->raise($member, $tenant);

        $this->actingAs($staff)
            ->from('/admin/support/'.$ticket->getKey())
            ->patch('/admin/support/'.$ticket->getKey(), ['assignee_id' => $member->getKey()])
            ->assertSessionHasErrors('assignee_id');

        $this->actingAs($member)->get('/admin/support')->assertForbidden();
    }
}
