<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TenantRole;
use App\Enums\WorkOrderCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\WorkOrder;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia work-order board.
 *
 * Reads go through the same ListWorkOrdersQuery as the JSON API and writes
 * through the same Actions, so these tests are about the board's own decisions:
 * how tasks are grouped into columns, how the category-derived board picker
 * counts, what the two toggles resolve to, and that these routes stay open to
 * any member exactly as routes/api.php has them.
 */
class WebWorkOrdersTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function tenantAndMember(TenantRole $role = TenantRole::Admin): array
    {
        $tenant = $this->createTenant();

        return [$tenant, $this->createMember($tenant, [$role])];
    }

    private function makeTask(
        User $author,
        string $title,
        TaskStatus $status = TaskStatus::Todo,
        ?WorkOrderCategory $category = WorkOrderCategory::Cellar,
        ?string $assigneeId = null,
        ?string $dueDate = null,
    ): WorkOrder {
        return WorkOrder::create([
            'title' => $title,
            'status' => $status,
            'category' => $category,
            'priority' => TaskPriority::Medium,
            'created_by_id' => $author->getKey(),
            'assignee_id' => $assigneeId,
            'due_date' => $dueDate,
        ]);
    }

    /** @return array<string, string> */
    private function tenantSession(Tenant $tenant): array
    {
        return [ActiveTenantSession::KEY => $tenant->getKey()];
    }

    public function test_the_board_groups_tasks_into_the_three_statuses(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        $this->makeTask($admin, 'Rack Malvazija');
        $this->makeTask($admin, 'Bottling run', TaskStatus::InProgress);
        $this->makeTask($admin, 'QC analysis', TaskStatus::Done);
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('WorkOrders/Index')
                    ->has('board.columns', 3)
                    ->where('board.total', 3);

                $columns = collect($page->toArray()['props']['board']['columns'])->keyBy('key');

                $this->assertSame(1, $columns['TODO']['count']);
                $this->assertSame(1, $columns['IN_PROGRESS']['count']);
                $this->assertSame(1, $columns['DONE']['count']);
                $this->assertSame('Rack Malvazija', $columns['TODO']['tasks'][0]['title']);
            });
    }

    /**
     * The design's named boards do not exist in this domain, so the picker is
     * built from the categories that have work. Counts ignore the selected
     * board so you can still see what you would switch to.
     */
    public function test_the_board_picker_lists_categories_and_counts_across_them(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        $this->makeTask($admin, 'Rack Malvazija', category: WorkOrderCategory::Cellar);
        $this->makeTask($admin, 'Prune block 4', category: WorkOrderCategory::Vineyard);
        $this->makeTask($admin, 'Service destemmer', category: WorkOrderCategory::Vineyard);
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders?category=CELLAR')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                // Only the chosen board's work is on the board itself…
                $this->assertSame(1, $page->toArray()['props']['board']['total']);

                // …but the picker still shows both, or you could not switch.
                $boards = collect($page->toArray()['props']['boards'])->keyBy('key');
                $this->assertSame(1, $boards['CELLAR']['count']);
                $this->assertSame(2, $boards['VINEYARD']['count']);
            });
    }

    /** Work with no category must stay reachable, or it is invisible. */
    public function test_uncategorised_work_gets_its_own_board(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        $this->makeTask($admin, 'Something loose', category: null);
        $this->makeTask($admin, 'Rack Malvazija', category: WorkOrderCategory::Cellar);
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders?category=none')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertSame(1, $props['board']['total']);
                $this->assertContains('none', array_column($props['boards'], 'key'));
            });
    }

    public function test_my_tasks_narrows_to_the_signed_in_member(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();
        $other = $this->createMember($tenant, [TenantRole::Cellar]);

        $this->actingAsTenant($tenant);
        $this->makeTask($admin, 'Mine', assigneeId: $admin->getKey());
        $this->makeTask($admin, 'Theirs', assigneeId: $other->getKey());
        $this->makeTask($admin, 'Unassigned work');
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders?mine=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('board.total', 1)
                ->where('filters.mine', true));
    }

    /** "Due soon" is a ceiling: undated work and work beyond the window drop out. */
    public function test_due_soon_keeps_only_work_inside_the_window(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        $this->makeTask($admin, 'This week', dueDate: now()->addDays(2)->toDateTimeString());
        $this->makeTask($admin, 'Next month', dueDate: now()->addDays(40)->toDateTimeString());
        $this->makeTask($admin, 'Someday');
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders?due_soon=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('board.total', 1)
                ->where('filters.due_soon', true));
    }

    public function test_search_matches_title_and_description(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        $this->makeTask($admin, 'Rack Malvazija');
        $this->makeTask($admin, 'Bottling run');
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders?search=Malvazija')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('board.total', 1));
    }

    public function test_a_card_carries_its_vessel_so_the_board_can_name_it(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        $vessel = Vessel::create([
            'name' => 'Tank A-2',
            'type' => 'TANK',
            'capacity_liters' => 5000,
            'current_volume' => 0,
            'is_active' => true,
        ]);
        $task = $this->makeTask($admin, 'Rack Malvazija');
        $task->forceFill(['vessel_id' => $vessel->getKey()])->save();
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('board.columns.0.tasks.0.vessel.name', 'Tank A-2')
                ->has('board.columns.0.tasks.0.created_by'));
    }

    public function test_creating_a_task(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->post('/work-orders', [
                'title' => 'Sulfite addition',
                'category' => 'CELLAR',
                'priority' => 'HIGH',
                'status' => 'TODO',
            ])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $task = WorkOrder::query()->firstOrFail();
        $this->assertSame('Sulfite addition', $task->title);
        $this->assertSame($admin->getKey(), $task->created_by_id);
        $this->forgetTenant();
    }

    /**
     * Dropping a card on Done must mean the same thing as ticking it, which is
     * why the drag goes through the status action rather than a bare update.
     */
    public function test_moving_a_card_to_done_stamps_completion(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        $task = $this->makeTask($admin, 'Rack Malvazija');
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->patch('/work-orders/'.$task->getKey().'/status', ['status' => 'DONE'])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $task->refresh();
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->forgetTenant();
    }

    public function test_reordering_within_a_column(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        $first = $this->makeTask($admin, 'First');
        $second = $this->makeTask($admin, 'Second');
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->post('/work-orders/reorder', ['ids' => [$second->getKey(), $first->getKey()]])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertSame(0, $second->refresh()->sort_order);
        $this->assertSame(1, $first->refresh()->sort_order);
        $this->forgetTenant();
    }

    public function test_updating_and_deleting_a_task(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        $task = $this->makeTask($admin, 'Rack Malvazija');
        $this->forgetTenant();

        $session = $this->tenantSession($tenant);

        $this->actingAs($admin)->withSession($session)
            ->patch('/work-orders/'.$task->getKey(), ['title' => 'Rack Teran', 'category' => null])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $task->refresh();
        $this->assertSame('Rack Teran', $task->title);
        $this->assertNull($task->category);
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($session)
            ->delete('/work-orders/'.$task->getKey())
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertNull(WorkOrder::query()->find($task->getKey()));
        $this->forgetTenant();
    }

    /**
     * Task planning is open to every member on the API, so the web routes must
     * be too — a role with no module capabilities at all still gets the board.
     */
    public function test_the_board_is_open_to_any_member(): void
    {
        [$tenant, $employee] = $this->tenantAndMember(TenantRole::Employee);

        $this->actingAs($employee)->withSession($this->tenantSession($tenant))
            ->get('/work-orders')
            ->assertOk();

        $this->actingAs($employee)->withSession($this->tenantSession($tenant))
            ->post('/work-orders', ['title' => 'Sweep the cellar'])
            ->assertRedirect();
    }

    public function test_a_guest_is_still_sent_to_login(): void
    {
        $this->get('/work-orders')->assertRedirect('/login');
    }
}
