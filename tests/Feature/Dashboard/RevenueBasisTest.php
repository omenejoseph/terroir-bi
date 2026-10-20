<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class RevenueBasisTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function seedOrders(): array
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'Konoba', 'email' => 'k@example.com', 'customer_type' => 'WHOLESALE']);

        $make = fn (string $no, OrderStatus $status, int $total): Order => Order::create([
            'order_number' => $no, 'status' => $status->value, 'total_amount' => $total,
            'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false,
        ]);

        // Created two months ago, shipped today: counts in this month, not two months ago.
        $shipped = $make('SHIP', OrderStatus::Shipped, 10000);
        $shipped->forceFill(['created_at' => now()->subMonths(2)])->save();
        OrderStatusHistory::create(['order_id' => $shipped->getKey(), 'status' => OrderStatus::Shipped, 'changed_by_id' => $admin->getKey()]);

        // Placed today but never shipped: only counts on the creation basis.
        $make('OPEN', OrderStatus::Received, 5000);

        $this->forgetTenant();

        return [$tenant, $admin];
    }

    public function test_shipped_basis_counts_shipped_orders_on_the_ship_date(): void
    {
        [$tenant, $admin] = $this->seedOrders();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard?period=mtd', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.stats.revenue', 10000)
            ->assertJsonPath('data.revenue_summary.mtd.current', 10000)
            ->assertJsonPath('data.revenue_by_channel.wholesale.current', 10000)
            // Order counts and analytics stay on the creation date.
            ->assertJsonPath('data.stats.total_orders', 1);
    }

    public function test_created_basis_counts_every_order_on_its_creation_date(): void
    {
        [$tenant, $admin] = $this->seedOrders();
        $tenant->settings()->update(['revenue_basis' => 'created']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard?period=mtd', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.stats.revenue', 5000)
            ->assertJsonPath('data.revenue_summary.mtd.current', 5000)
            ->assertJsonPath('data.revenue_summary.total.current', 15000);
    }

    public function test_unshipped_orders_never_count_on_the_shipped_basis(): void
    {
        [$tenant, $admin] = $this->seedOrders();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard?period=ytd', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.revenue_summary.total.current', 10000);
    }

    public function test_the_setting_is_saved_and_validated(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);
        $base = ['name' => 'W', 'default_locale' => 'hr', 'timezone' => 'Europe/Zagreb'];

        $this->patchJson('/api/v1/settings', $base + ['revenue_basis' => 'bogus'], $this->tenantHeader($tenant))
            ->assertUnprocessable();
        $this->patchJson('/api/v1/settings', $base + ['revenue_basis' => 'created'], $this->tenantHeader($tenant))
            ->assertOk();

        $this->assertSame('created', DB::table('tenant_settings')->where('tenant_id', $tenant->getKey())->value('revenue_basis'));
    }
}
