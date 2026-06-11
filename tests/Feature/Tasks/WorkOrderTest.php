<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class WorkOrderTest extends TestCase
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
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_create_assign_and_complete_a_task(): void
    {
        $id = $this->postJson('/api/v1/work-orders', [
            'title' => 'Bottle the Plavac', 'priority' => 'HIGH',
            'due_date' => now()->addDay()->toDateString(), 'assignee_id' => $this->teammate->getKey(),
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.status', 'TODO')
            ->assertJsonPath('data.assignee.id', $this->teammate->getKey())
            ->json('data.id');

        // Assignee can see it filtered to them.
        Sanctum::actingAs($this->teammate);
        $this->getJson("/api/v1/work-orders?assignee_id={$this->teammate->getKey()}", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Completing sets completed_at.
        $this->patchJson("/api/v1/work-orders/{$id}/status", ['status' => 'DONE'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'DONE')
            ->assertJsonPath('data.completed_at', fn ($v) => $v !== null);
    }

    public function test_reorder_persists_sort_order(): void
    {
        $this->actingAsTenant($this->tenant);
        $a = WorkOrder::create(['title' => 'A', 'created_by_id' => $this->admin->getKey(), 'sort_order' => 0]);
        $b = WorkOrder::create(['title' => 'B', 'created_by_id' => $this->admin->getKey(), 'sort_order' => 1]);
        $this->forgetTenant();

        // Move B before A.
        $this->postJson('/api/v1/work-orders/reorder', ['ids' => [$b->getKey(), $a->getKey()]], $this->headers())
            ->assertNoContent();

        $this->assertSame(0, $b->refresh()->sort_order);
        $this->assertSame(1, $a->refresh()->sort_order);

        // Listing reflects the new order.
        $this->getJson('/api/v1/work-orders', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.id', $b->getKey());
    }

    public function test_stats_count_overdue(): void
    {
        $this->actingAsTenant($this->tenant);
        WorkOrder::create(['title' => 'late', 'created_by_id' => $this->admin->getKey(), 'due_date' => now()->subDays(2), 'status' => 'TODO']);
        WorkOrder::create(['title' => 'fine', 'created_by_id' => $this->admin->getKey(), 'status' => 'IN_PROGRESS']);
        WorkOrder::create(['title' => 'done late', 'created_by_id' => $this->admin->getKey(), 'due_date' => now()->subDays(5), 'status' => 'DONE']);
        $this->forgetTenant();

        $this->getJson('/api/v1/work-orders/stats', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.todo', 1)
            ->assertJsonPath('data.in_progress', 1)
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.overdue', 1); // only the TODO past-due one
    }

    public function test_stats_can_be_filtered_by_a_due_date_range(): void
    {
        $this->actingAsTenant($this->tenant);
        WorkOrder::create(['title' => 'soon', 'created_by_id' => $this->admin->getKey(), 'due_date' => now()->addDays(3), 'status' => 'TODO']);
        WorkOrder::create(['title' => 'later', 'created_by_id' => $this->admin->getKey(), 'due_date' => now()->addDays(20), 'status' => 'TODO']);
        WorkOrder::create(['title' => 'undated', 'created_by_id' => $this->admin->getKey(), 'status' => 'TODO']);
        $this->forgetTenant();

        // 7D horizon: only the task due in 3 days (undated + far-out excluded).
        $this->getJson('/api/v1/work-orders/stats?range=7D', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.todo', 1);

        // 30D includes both dated tasks.
        $this->getJson('/api/v1/work-orders/stats?range=30D', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.todo', 2);

        // ALL counts everything, including the undated one.
        $this->getJson('/api/v1/work-orders/stats?range=ALL', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.todo', 3);
    }
}
