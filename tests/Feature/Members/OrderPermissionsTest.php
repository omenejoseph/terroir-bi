<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Authorization\MembershipContext;
use App\Enums\TenantRole;
use App\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class OrderPermissionsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /**
     * @param  list<TenantRole>  $roles
     * @param  array<string, mixed>  $flags
     */
    private function membership(array $roles, array $flags = []): Membership
    {
        $tenant = $this->createTenant();
        $user = $this->createUser();

        return Membership::create(array_merge([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'roles' => collect($roles),
            'joined_at' => now(),
        ], $flags));
    }

    public function test_admin_always_edits_and_sees_shipped_regardless_of_flags(): void
    {
        $admin = $this->membership([TenantRole::Admin]); // flags default false

        $this->assertTrue($admin->canEditOrders());
        $this->assertTrue($admin->canSeeShippedOrders());
    }

    public function test_non_admin_obeys_per_membership_flags(): void
    {
        $restricted = $this->membership([TenantRole::Orders]);
        $this->assertFalse($restricted->canEditOrders());
        $this->assertFalse($restricted->canSeeShippedOrders());

        $granted = $this->membership([TenantRole::Orders], [
            'can_edit_orders' => true,
            'can_see_shipped_orders' => true,
        ]);
        $this->assertTrue($granted->canEditOrders());
        $this->assertTrue($granted->canSeeShippedOrders());
    }

    public function test_financial_visibility_follows_role_capabilities(): void
    {
        $context = app(MembershipContext::class);

        $context->set($this->membership([TenantRole::Sales]));
        $this->assertTrue($context->canSeeFinancials());

        $context->set($this->membership([TenantRole::Kitchen]));
        $this->assertFalse($context->canSeeFinancials());

        $context->set($this->membership([TenantRole::Admin]));
        $this->assertTrue($context->canSeeFinancials()); // wildcard
    }

    public function test_membership_context_reflects_order_flags(): void
    {
        $context = app(MembershipContext::class);

        $context->set($this->membership([TenantRole::Orders], ['can_edit_orders' => true]));
        $this->assertTrue($context->canEditOrders());
        $this->assertFalse($context->canSeeShippedOrders());
    }
}
