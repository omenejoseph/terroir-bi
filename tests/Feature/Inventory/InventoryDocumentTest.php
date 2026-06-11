<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Uploads\Contracts\ObjectStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Support\FakeObjectStore;
use Tests\TestCase;

class InventoryDocumentTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private FakeObjectStore $store;

    private InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new FakeObjectStore;
        $this->app->instance(ObjectStore::class, $this->store);

        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->actingAsTenant($this->tenant);
        $this->item = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
        ]);
        $this->forgetTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_upload_key_is_tenant_scoped_under_inventory_docs(): void
    {
        Sanctum::actingAs($this->admin);

        $key = $this->postJson('/api/v1/uploads/presign', [
            'purpose' => 'inventory_document',
            'filename' => 'lab-report.pdf',
            'content_type' => 'application/pdf',
            'size' => 2048,
        ], $this->headers())->assertOk()->json('data.key');

        $this->assertStringStartsWith('tenants/'.$this->tenant->getKey().'/inventory/docs/', $key);
        $this->assertStringEndsWith('.pdf', $key);
    }

    public function test_attach_list_and_delete_a_document(): void
    {
        Sanctum::actingAs($this->admin);

        // Presign, simulate the bucket upload, then attach.
        $key = $this->postJson('/api/v1/uploads/presign', [
            'purpose' => 'inventory_document',
            'filename' => 'cert.pdf',
            'content_type' => 'application/pdf',
            'size' => 4096,
        ], $this->headers())->json('data.key');
        $this->store->store($key, 4096);

        $id = $this->postJson("/api/v1/inventory-items/{$this->item->getKey()}/documents", [
            'key' => $key,
            'name' => 'Certificate.pdf',
            'content_type' => 'application/pdf',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Certificate.pdf')
            ->assertJsonPath('data.size_bytes', 4096)
            ->assertJsonPath('data.content_type', 'application/pdf')
            ->json('data.id');

        $this->getJson("/api/v1/inventory-items/{$this->item->getKey()}/documents", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Certificate.pdf');

        $this->deleteJson("/api/v1/inventory-items/{$this->item->getKey()}/documents/{$id}", [], $this->headers())
            ->assertNoContent();

        $this->assertDatabaseMissing('inventory_documents', ['id' => $id]);
        $this->assertArrayNotHasKey($key, $this->store->objects); // also removed from the bucket
    }

    public function test_disallowed_type_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/inventory-items/{$this->item->getKey()}/documents", [
            'key' => 'tenants/'.$this->tenant->getKey().'/inventory/docs/x.exe',
            'name' => 'malware.exe',
            'content_type' => 'application/x-msdownload',
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors(['content_type']);
    }

    public function test_listing_requires_inventory_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::WineClub]);
        Sanctum::actingAs($member);

        $this->getJson("/api/v1/inventory-items/{$this->item->getKey()}/documents", $this->headers())
            ->assertForbidden();
    }
}
