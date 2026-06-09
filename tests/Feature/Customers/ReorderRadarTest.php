<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ReorderRadarTest extends TestCase
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

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function orderAt(Customer $customer, int $daysAgo, int $minor = 10000): void
    {
        static $n = 0;
        $n++;
        $order = Order::create([
            'order_number' => 'ORD-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->getKey(),
            'created_by_id' => $this->admin->getKey(),
            'total_amount' => $minor,
        ]);
        $order->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    }

    public function test_overdue_customer_is_flagged_and_muted_after_contact(): void
    {
        $this->actingAsTenant($this->tenant);
        $overdue = Customer::create(['company_name' => 'Slipping Bar', 'email' => 's@example.com']);
        $this->orderAt($overdue, 40);
        $this->orderAt($overdue, 30);
        $this->orderAt($overdue, 20); // median gap 10d, last 20d ago → ratio 2.0 → overdue

        $tooFew = Customer::create(['company_name' => 'New Bar', 'email' => 'n@example.com']);
        $this->orderAt($tooFew, 5);
        $this->orderAt($tooFew, 2); // only 2 orders → excluded
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/customers/reorder-radar', $this->headers())->assertOk();
        $ids = array_column((array) $response->json('data.rows'), 'customer_id');
        $this->assertContains($overdue->getKey(), $ids);
        $this->assertNotContains($tooFew->getKey(), $ids);
        $response->assertJsonPath('data.rows.0.status', 'overdue');
        $this->assertGreaterThanOrEqual(1, $response->json('data.counts.overdue'));

        // Mark contacted → muted on the radar.
        $this->postJson("/api/v1/customers/{$overdue->getKey()}/contacted", ['contacted' => true], $this->headers())
            ->assertOk();

        $ids = array_column((array) $this->getJson('/api/v1/customers/reorder-radar', $this->headers())->json('data.rows'), 'customer_id');
        $this->assertNotContains($overdue->getKey(), $ids);
    }
}
