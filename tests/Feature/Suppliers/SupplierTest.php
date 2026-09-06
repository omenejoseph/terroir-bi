<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Enums\TenantRole;
use App\Models\Cost;
use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class SupplierTest extends TestCase
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
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_supplier_crud_and_oib_unique_per_tenant(): void
    {
        $id = $this->postJson('/api/v1/suppliers', [
            'company_name' => 'Staklo d.o.o.', 'tax_id' => '11111111111', 'email' => 'sales@staklo.hr',
        ], $this->headers())->assertCreated()->json('data.id');

        // OIB must be unique within the tenant.
        $this->postJson('/api/v1/suppliers', [
            'company_name' => 'Dup', 'tax_id' => '11111111111',
        ], $this->headers())->assertStatus(422);

        $this->patchJson("/api/v1/suppliers/{$id}", ['payment_terms' => 'Net 30'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.payment_terms', 'Net 30');

        $this->getJson('/api/v1/suppliers?search=staklo', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_price_list_upsert_is_keyed_on_description(): void
    {
        $this->actingAsTenant($this->tenant);
        $supplier = Supplier::create(['company_name' => 'Staklo']);
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units']);
        $this->forgetTenant();

        $this->postJson("/api/v1/suppliers/{$supplier->getKey()}/price-items", [
            'description' => 'Natural cork 44mm', 'unit_price' => 25, 'unit' => 'units', 'inventory_item_id' => $cork->getKey(),
        ], $this->headers())->assertCreated()->assertJsonPath('data.unit_price.minor', 25);

        // Same description → updates in place (no duplicate row).
        $this->postJson("/api/v1/suppliers/{$supplier->getKey()}/price-items", [
            'description' => 'Natural cork 44mm', 'unit_price' => 30,
        ], $this->headers())->assertCreated()->assertJsonPath('data.unit_price.minor', 30);

        $this->assertDatabaseCount('supplier_price_items', 1);

        $this->getJson("/api/v1/suppliers/{$supplier->getKey()}", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data.price_items')
            ->assertJsonPath('data.price_items.0.unit_price.minor', 30);
    }

    public function test_supplier_stats_summarise_costs_and_price_items(): void
    {
        $this->actingAsTenant($this->tenant);
        $supplier = Supplier::create(['company_name' => 'Staklo']);
        $supplier->priceItems()->create(['description' => 'Cork', 'unit_price' => 25]);
        $supplier->priceItems()->create(['description' => 'Capsule', 'unit_price' => 30]);
        Cost::create(['date' => '2026-06-01', 'total_amount' => 12000, 'category' => 'Packaging', 'supplier_id' => $supplier->getKey(), 'created_by_id' => $this->admin->getKey()]);
        Cost::create(['date' => '2026-06-02', 'total_amount' => 8000, 'category' => 'Packaging', 'supplier_id' => $supplier->getKey(), 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        $this->getJson("/api/v1/suppliers/{$supplier->getKey()}/stats", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.price_items', 2)
            ->assertJsonPath('data.cost_entries', 2)
            ->assertJsonPath('data.total_costs.minor', 20000);
    }

    public function test_supplier_stats_sums_and_counts_costs_across_categories_and_dates(): void
    {
        $this->actingAsTenant($this->tenant);
        $supplier = Supplier::create(['company_name' => 'Staklo']);
        Cost::create(['date' => '2026-01-05', 'total_amount' => 15075, 'category' => 'Packaging', 'supplier_id' => $supplier->getKey(), 'created_by_id' => $this->admin->getKey()]);
        Cost::create(['date' => '2026-03-20', 'total_amount' => 4250, 'category' => 'Freight', 'supplier_id' => $supplier->getKey(), 'created_by_id' => $this->admin->getKey()]);
        Cost::create(['date' => '2026-06-30', 'total_amount' => 999, 'category' => 'Utilities', 'supplier_id' => $supplier->getKey(), 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        $this->getJson("/api/v1/suppliers/{$supplier->getKey()}/stats", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.cost_entries', 3)
            ->assertJsonPath('data.total_costs.minor', 15075 + 4250 + 999);
    }

    public function test_supplier_stats_report_zero_when_supplier_has_no_costs(): void
    {
        $this->actingAsTenant($this->tenant);
        $supplier = Supplier::create(['company_name' => 'NoCosts']);
        $this->forgetTenant();

        $this->getJson("/api/v1/suppliers/{$supplier->getKey()}/stats", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.price_items', 0)
            ->assertJsonPath('data.cost_entries', 0)
            ->assertJsonPath('data.total_costs.minor', 0);
    }

    public function test_supplier_stats_exclude_costs_belonging_to_another_supplier(): void
    {
        $this->actingAsTenant($this->tenant);
        $target = Supplier::create(['company_name' => 'Target']);
        $other = Supplier::create(['company_name' => 'Other']);

        Cost::create(['date' => '2026-06-01', 'total_amount' => 5000, 'category' => 'Packaging', 'supplier_id' => $target->getKey(), 'created_by_id' => $this->admin->getKey()]);
        Cost::create(['date' => '2026-06-02', 'total_amount' => 99999, 'category' => 'Freight', 'supplier_id' => $other->getKey(), 'created_by_id' => $this->admin->getKey()]);
        Cost::create(['date' => '2026-06-03', 'total_amount' => 12345, 'category' => 'Freight', 'supplier_id' => $other->getKey(), 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        // The target supplier only sees its own cost, never the other supplier's.
        $this->getJson("/api/v1/suppliers/{$target->getKey()}/stats", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.cost_entries', 1)
            ->assertJsonPath('data.total_costs.minor', 5000);

        // And vice versa: the other supplier doesn't pick up the target's cost.
        $this->getJson("/api/v1/suppliers/{$other->getKey()}/stats", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.cost_entries', 2)
            ->assertJsonPath('data.total_costs.minor', 99999 + 12345);
    }

    public function test_price_changes_are_tracked_and_listed(): void
    {
        $this->actingAsTenant($this->tenant);
        $supplier = Supplier::create(['company_name' => 'Staklo']);
        // Create → one change (old null, new 25). Same-price save → no new change.
        $item = $supplier->priceItems()->create(['description' => 'Cork', 'unit_price' => 25, 'unit' => 'units']);
        $item->update(['notes' => 'touch']); // non-price change → not tracked
        // Price change → another row (old 25, new 40).
        $item->update(['unit_price' => 40]);
        $this->forgetTenant();

        $this->assertDatabaseCount('supplier_price_changes', 2);

        $res = $this->getJson("/api/v1/suppliers/{$supplier->getKey()}/price-changes", $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data');
        // Newest first: the 25 → 40 change.
        $res->assertJsonPath('data.0.old_price.minor', 25)
            ->assertJsonPath('data.0.new_price.minor', 40)
            ->assertJsonPath('data.1.old_price', null)
            ->assertJsonPath('data.1.new_price.minor', 25);

        $this->getJson("/api/v1/suppliers/{$supplier->getKey()}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.price_changes_count', 2);
    }

    public function test_price_item_can_be_edited_by_id(): void
    {
        $this->actingAsTenant($this->tenant);
        $supplier = Supplier::create(['company_name' => 'Staklo']);
        $item = $supplier->priceItems()->create([
            'description' => 'Natural cork 44mm', 'unit_price' => 25, 'unit' => 'units',
        ]);
        $this->forgetTenant();

        $this->patchJson(
            "/api/v1/suppliers/{$supplier->getKey()}/price-items/{$item->getKey()}",
            ['description' => 'Premium cork 44mm', 'unit_price' => 40, 'unit' => 'unit'],
            $this->headers(),
        )
            ->assertOk()
            ->assertJsonPath('data.description', 'Premium cork 44mm')
            ->assertJsonPath('data.unit_price.minor', 40)
            ->assertJsonPath('data.unit', 'unit');

        // Edited in place — no new row.
        $this->assertDatabaseCount('supplier_price_items', 1);
    }

    public function test_price_item_update_rejects_a_foreign_supplier(): void
    {
        $this->actingAsTenant($this->tenant);
        $a = Supplier::create(['company_name' => 'A']);
        $b = Supplier::create(['company_name' => 'B']);
        $item = $b->priceItems()->create(['description' => 'X', 'unit_price' => 10]);
        $this->forgetTenant();

        $this->patchJson(
            "/api/v1/suppliers/{$a->getKey()}/price-items/{$item->getKey()}",
            ['unit_price' => 99],
            $this->headers(),
        )->assertNotFound();
    }
}
