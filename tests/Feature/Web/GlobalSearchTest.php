<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\Module;
use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function seedRecords(Tenant $tenant, User $admin): void
    {
        $this->actingAsTenant($tenant);

        $customer = Customer::create(['company_name' => 'Konoba Rivalja', 'email' => 'riva@example.com']);
        Order::create([
            'order_number' => 'RIVA-1', 'status' => OrderStatus::Received, 'total_amount' => 10000,
            'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false,
        ]);
        InventoryItem::create(['name' => 'Rivaljski Plavac', 'sku' => 'RIVA-PLV', 'category' => 'FINISHED', 'unit' => 'bottles']);

        $this->forgetTenant();
    }

    /** @return array{Tenant, User} */
    private function acting(?Plan $plan = null): array
    {
        $tenant = $this->createTenant($plan !== null ? ['plan_id' => $plan->getKey()] : []);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->seedRecords($tenant, $admin);

        $this->actingAs($admin)->withSession([ActiveTenantSession::KEY => $tenant->getKey()]);

        return [$tenant, $admin];
    }

    public function test_full_member_gets_matches_across_every_category(): void
    {
        $this->acting();

        $this->getJson('/search?q=riva')
            ->assertOk()
            ->assertJsonPath('orders.0.title', 'RIVA-1')
            ->assertJsonPath('customers.0.title', 'Konoba Rivalja')
            ->assertJsonPath('inventory.0.title', 'Rivaljski Plavac');
    }

    public function test_category_without_capability_is_omitted_not_forbidden(): void
    {
        $tenant = $this->createTenant();
        // Employee holds none of orders.view/customers.view/inventory.view.
        $member = $this->createMember($tenant, [TenantRole::Employee]);
        $this->seedRecords($tenant, $member);
        $this->actingAs($member)->withSession([ActiveTenantSession::KEY => $tenant->getKey()]);

        $this->getJson('/search?q=riva')
            ->assertOk()
            ->assertExactJson(['orders' => [], 'customers' => [], 'inventory' => []]);
    }

    public function test_category_missing_from_plan_is_omitted(): void
    {
        $plan = Plan::create([
            'name' => 'search-plan', 'slug' => 'search-plan', 'currency' => 'EUR',
            'modules' => [Module::Dashboard->value, Module::Orders->value, Module::Customers->value],
        ]);
        $this->acting($plan);

        $this->getJson('/search?q=riva')
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('inventory', []);
    }

    public function test_short_query_returns_everything_empty(): void
    {
        $this->acting();

        $this->getJson('/search?q=r')
            ->assertOk()
            ->assertExactJson(['orders' => [], 'customers' => [], 'inventory' => []]);
    }

    public function test_results_are_scoped_to_the_current_tenant(): void
    {
        [$tenant, $admin] = $this->acting();

        $foreign = $this->createTenant();
        $foreignAdmin = $this->createMember($foreign, [TenantRole::Admin]);
        $this->seedRecords($foreign, $foreignAdmin);

        // Re-establish the original tenant's session (seeding the foreign
        // tenant above switched the acting user/session).
        $this->actingAs($admin)->withSession([ActiveTenantSession::KEY => $tenant->getKey()]);

        $this->getJson('/search?q=riva')
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonCount(1, 'customers')
            ->assertJsonCount(1, 'inventory');
    }
}
