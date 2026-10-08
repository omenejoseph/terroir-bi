<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\TaskStatus;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CancelledWorkOrderTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
    }

    private function task(string $title, TaskStatus $status, bool $overdue = true): WorkOrder
    {
        $this->actingAsTenant($this->tenant);
        $task = WorkOrder::create([
            'title' => $title, 'status' => $status, 'priority' => 'MEDIUM', 'created_by_id' => $this->admin->getKey(),
            'due_date' => $overdue ? now()->subDays(3) : now()->addDays(3),
        ]);
        $this->forgetTenant();

        return $task;
    }

    public function test_a_task_can_be_cancelled_and_reopened(): void
    {
        $task = $this->task('Bottle the Plavac', TaskStatus::Done);
        $task->forceFill(['completed_at' => now()])->save();

        $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->patch("/work-orders/{$task->getKey()}/status", ['status' => 'CANCELLED'])->assertRedirect();
        $task->refresh();
        $this->assertSame(TaskStatus::Cancelled, $task->status);
        $this->assertNull($task->completed_at, 'a cancelled task was not completed');

        $this->patch("/work-orders/{$task->getKey()}/status", ['status' => 'TODO'])->assertRedirect();
        $this->assertSame(TaskStatus::Todo, $task->refresh()->status);
    }

    public function test_an_unknown_status_is_still_refused(): void
    {
        $task = $this->task('Plan', TaskStatus::Todo);

        $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->patch("/work-orders/{$task->getKey()}/status", ['status' => 'PARKED'])->assertSessionHasErrors('status');
    }

    public function test_the_board_gets_a_cancelled_column(): void
    {
        $this->task('Dropped', TaskStatus::Cancelled);
        $this->task('Live', TaskStatus::Todo);

        $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->get('/work-orders')->assertInertia(function (AssertableInertia $page): void {
                $columns = array_column($page->toArray()['props']['board']['columns'], null, 'key');
                $this->assertSame(['TODO', 'IN_PROGRESS', 'DONE', 'CANCELLED'], array_keys($columns));
                $this->assertSame('Cancelled', $columns['CANCELLED']['label']);
                $this->assertSame(['Dropped'], array_column($columns['CANCELLED']['tasks'], 'title'));
            });
    }

    public function test_cancelled_tasks_are_never_overdue(): void
    {
        $this->task('Dropped', TaskStatus::Cancelled);
        $this->task('Slipped', TaskStatus::Todo);
        $this->task('Finished', TaskStatus::Done);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/work-orders/stats', $this->tenantHeader($this->tenant))
            ->assertOk()
            ->assertJsonPath('data.overdue', 1)        // only the open, late one
            ->assertJsonPath('data.cancelled', 1)
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.todo', 1);
    }

    public function test_cancelled_tasks_do_not_count_as_open_work_on_the_dashboard(): void
    {
        $this->task('Dropped', TaskStatus::Cancelled);
        $this->task('Slipped', TaskStatus::Todo);
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/dashboard', $this->tenantHeader($this->tenant))->assertOk();
        $json = json_encode($response->json());
        $this->assertIsString($json);
        $this->assertStringContainsString('Slipped', $json);
        $this->assertStringNotContainsString('Dropped', $json);
    }

    public function test_status_helpers(): void
    {
        $this->assertSame([TaskStatus::Done, TaskStatus::Cancelled], TaskStatus::closed());
        $this->assertTrue(TaskStatus::Cancelled->isClosed());
        $this->assertFalse(TaskStatus::Cancelled->isDone());
        $this->assertFalse(TaskStatus::InProgress->isClosed());
    }
}
