<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_team_member_can_create_a_customer(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Team]));

        $this->postJson('/api/v1/customers', [
            'company_name' => 'Konoba More',
            'contact_name' => 'Ivo',
            'email' => 'ivo@more.hr',
            'rebate_percent' => 10,
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.company_name', 'Konoba More')
            ->assertJsonPath('data.effective_rebate_percent', '10.00');
    }

    public function test_quick_create_customer(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Team]));

        $this->postJson('/api/v1/customers/quick', [
            'company_name' => 'Quick Co',
            'email' => 'quick@co.hr',
        ], $this->tenantHeader($tenant))->assertCreated();
    }

    public function test_email_is_unique_per_tenant(): void
    {
        $a = $this->createTenant();
        $admin = $this->createMember($a, [TenantRole::Admin]);
        $b = $this->createTenant();
        $this->createMembershipFor($admin, $b, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $payload = ['company_name' => 'X', 'email' => 'dup@x.hr'];
        $this->postJson('/api/v1/customers', $payload, $this->tenantHeader($a))->assertCreated();
        $this->postJson('/api/v1/customers', $payload, $this->tenantHeader($b))->assertCreated();
        $this->postJson('/api/v1/customers', $payload, $this->tenantHeader($a))
            ->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_customers_are_isolated_per_tenant(): void
    {
        $a = $this->createTenant();
        $admin = $this->createMember($a, [TenantRole::Admin]);
        $this->actingAsTenant($a);
        $customer = Customer::create(['company_name' => 'A', 'email' => 'a@a.hr']);
        $this->forgetTenant();

        $b = $this->createTenant();
        $this->createMembershipFor($admin, $b, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/customers/{$customer->getKey()}", $this->tenantHeader($b))->assertNotFound();
    }

    public function test_only_admin_can_delete_a_customer(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $team = $this->createMember($tenant, [TenantRole::Team]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'A', 'email' => 'a@a.hr']);
        $this->forgetTenant();

        Sanctum::actingAs($team);
        $this->deleteJson("/api/v1/customers/{$customer->getKey()}", [], $this->tenantHeader($tenant))->assertForbidden();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/customers/{$customer->getKey()}", [], $this->tenantHeader($tenant))->assertNoContent();
    }

    public function test_admin_can_issue_and_revoke_order_token(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $team = $this->createMember($tenant, [TenantRole::Team]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'A', 'email' => 'a@a.hr']);
        $this->forgetTenant();

        // Team cannot manage tokens.
        Sanctum::actingAs($team);
        $this->postJson("/api/v1/customers/{$customer->getKey()}/order-token", [], $this->tenantHeader($tenant))
            ->assertForbidden();

        Sanctum::actingAs($admin);
        $token = $this->postJson("/api/v1/customers/{$customer->getKey()}/order-token", [], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.has_order_token', true)
            ->json('data.order_token');
        $this->assertIsString($token);

        $this->deleteJson("/api/v1/customers/{$customer->getKey()}/order-token", [], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.has_order_token', false);
    }
}
