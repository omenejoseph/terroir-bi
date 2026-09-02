<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia inventory pages. These read through the same
 * ListInventoryItemsQuery + InventoryItemPresenter as the JSON API, so the
 * assertions here are about the page envelope and the capability gates, not a
 * second copy of the listing rules.
 */
class WebInventoryTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function tenantAndAdmin(): array
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        return [$tenant, $admin];
    }

    private function makeItem(string $name, string $sku): InventoryItem
    {
        return InventoryItem::create([
            'name' => $name,
            'sku' => $sku,
            'category' => 'RAW_MATERIAL',
            'unit' => 'units',
            'current_stock' => '10',
        ]);
    }

    public function test_index_renders_items_with_pagination_meta(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeItem('Cork', 'CORK-1');
        $this->makeItem('Label', 'LBL-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Index')
                ->has('items.data', 2)
                ->where('items.meta.total', 2)
                ->has('items.data.0.image_url')
                ->where('filters.search', null));
    }

    public function test_index_filters_by_search(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeItem('Cork', 'CORK-1');
        $this->makeItem('Label', 'LBL-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory?search=Cork')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.name', 'Cork')
                ->where('filters.search', 'Cork'));
    }

    public function test_taxonomy_is_only_sent_when_requested(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('taxonomy'));
    }

    public function test_show_renders_a_single_item(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Show')
                ->where('item.sku', 'CORK-1'));
    }

    public function test_shared_props_expose_resolved_capabilities(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        // ADMIN holds the wildcard, so the client's can() short-circuits to true.
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.capabilities', ['*'])
                ->where('auth.roles', ['ADMIN'])
                ->where('tenant.id', $tenant->getKey())
                ->has('auth.user.email'));
    }

    public function test_member_without_the_capability_cannot_open_inventory(): void
    {
        $tenant = $this->createTenant();
        // HOSPITALITY grants nothing, so inventory.view must fail closed.
        $member = $this->createMember($tenant, [TenantRole::Hospitality]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertForbidden();
    }

    public function test_delete_deactivates_an_item_referenced_by_orders(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete('/inventory/'.$item->getKey())
            ->assertRedirect('/inventory')
            ->assertSessionHas('success');

        // No order lines reference it, so it is hard-deleted.
        $this->actingAsTenant($tenant);
        $this->assertNull(InventoryItem::query()->find($item->getKey()));
        $this->forgetTenant();
    }
}
