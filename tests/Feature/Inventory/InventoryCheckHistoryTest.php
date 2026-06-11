<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class InventoryCheckHistoryTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private InventoryItem $a;

    private InventoryItem $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
        $this->a = InventoryItem::create(['name' => 'A', 'sku' => 'SKU-A', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '100.000']);
        $this->b = InventoryItem::create(['name' => 'B', 'sku' => 'SKU-B', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '50.000']);
        $this->forgetTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_apply_records_an_audited_check_with_only_adjusted_lines(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/inventory-items/check', [
            'items' => [
                ['item_id' => $this->a->getKey(), 'physical_count' => '90'], // −10 → adjusted
                ['item_id' => $this->b->getKey(), 'physical_count' => '50'], // match → not recorded
            ],
        ], $this->headers())->assertOk();

        // One check, two counted, one adjusted, net −10. Only the changed line stored.
        $this->getJson('/api/v1/inventory-checks', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.items_counted', 2)
            ->assertJsonPath('data.0.items_adjusted', 1)
            ->assertJsonPath('data.0.net_difference', '-10.000')
            ->assertJsonPath('data.0.performed_by', trim($this->admin->first_name.' '.$this->admin->last_name));

        $id = $this->getJson('/api/v1/inventory-checks', $this->headers())->json('data.0.id');

        $this->getJson("/api/v1/inventory-checks/{$id}", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.sku', 'SKU-A')
            ->assertJsonPath('data.lines.0.system_count', '100.000')
            ->assertJsonPath('data.lines.0.physical_count', '90.000')
            ->assertJsonPath('data.lines.0.difference', '-10.000');
    }

    public function test_apply_with_no_changes_records_nothing(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/inventory-items/check', [
            'items' => [['item_id' => $this->a->getKey(), 'physical_count' => '100']],
        ], $this->headers())->assertOk();

        $this->getJson('/api/v1/inventory-checks', $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_history_requires_inventory_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::WineClub]);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/inventory-checks', $this->headers())->assertForbidden();
    }
}
