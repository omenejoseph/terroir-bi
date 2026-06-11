<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class BottleAnalysisTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function item(Tenant $tenant): InventoryItem
    {
        $this->actingAsTenant($tenant);
        $item = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
        ]);
        $this->forgetTenant();

        return $item;
    }

    public function test_create_and_list_analyses(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $item = $this->item($tenant);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/bottle-analyses", [
            'analyzed_on' => '2026-06-10',
            'ph' => 3.45,
            'alcohol' => 13.5,
            'free_so2' => 25,
            'note' => 'Pre-bottling',
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.analyzed_on', '2026-06-10')
            ->assertJsonPath('data.ph', 3.45)
            ->assertJsonPath('data.alcohol', 13.5)
            ->assertJsonPath('data.free_so2', 25)
            ->assertJsonPath('data.total_acidity', null)
            ->assertJsonPath('data.note', 'Pre-bottling');

        $this->getJson("/api/v1/inventory-items/{$item->getKey()}/bottle-analyses", $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ph', 3.45);
    }

    public function test_only_the_date_is_required(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $item = $this->item($tenant);

        Sanctum::actingAs($admin);
        // No measurements at all — still valid.
        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/bottle-analyses", [
            'analyzed_on' => '2026-06-10',
        ], $this->tenantHeader($tenant))->assertCreated();

        // Missing date fails.
        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/bottle-analyses", [
            'ph' => 3.4,
        ], $this->tenantHeader($tenant))->assertStatus(422)->assertJsonValidationErrors(['analyzed_on']);
    }

    public function test_delete_analysis(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $item = $this->item($tenant);

        $this->actingAsTenant($tenant);
        $analysis = $item->bottleAnalyses()->create(['analyzed_on' => '2026-06-01', 'ph' => 3.2]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->deleteJson(
            "/api/v1/inventory-items/{$item->getKey()}/bottle-analyses/{$analysis->getKey()}",
            [],
            $this->tenantHeader($tenant),
        )->assertNoContent();

        $this->assertDatabaseMissing('bottle_analyses', ['id' => $analysis->getKey()]);
    }

    public function test_listing_requires_inventory_visibility(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::WineClub]);
        $item = $this->item($tenant);

        Sanctum::actingAs($member);
        $this->getJson("/api/v1/inventory-items/{$item->getKey()}/bottle-analyses", $this->tenantHeader($tenant))
            ->assertForbidden();
    }
}
