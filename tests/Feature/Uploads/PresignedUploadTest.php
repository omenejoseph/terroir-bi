<?php

declare(strict_types=1);

namespace Tests\Feature\Uploads;

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

class PresignedUploadTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private FakeObjectStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new FakeObjectStore;
        $this->app->instance(ObjectStore::class, $this->store);

        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function presign(string $contentType = 'image/png', int $size = 1000, string $purpose = 'inventory_image'): string
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/v1/uploads/presign', [
            'purpose' => $purpose, 'filename' => 'photo.png', 'content_type' => $contentType, 'size' => $size,
        ], $this->headers())->assertOk()->json('data.key');
    }

    public function test_presign_returns_a_tenant_scoped_url_with_signed_content_type(): void
    {
        Sanctum::actingAs($this->admin);

        $prefix = 'tenants/'.$this->tenant->getKey().'/inventory/images/';

        $response = $this->postJson('/api/v1/uploads/presign', [
            'purpose' => 'inventory_image', 'filename' => 'evil.exe', 'content_type' => 'image/png', 'size' => 2048,
        ], $this->headers())->assertOk();

        $key = $response->json('data.key');
        $this->assertStringStartsWith($prefix, $key);
        $this->assertStringEndsWith('.png', $key); // extension derived from MIME, not filename
        $response->assertJsonPath('data.method', 'PUT')
            ->assertJsonPath('data.headers.Content-Type', 'image/png')
            ->assertJsonPath('data.max_bytes', 5 * 1024 * 1024);
        $this->assertStringContainsString('X-Amz-Signature', $response->json('data.url'));
    }

    public function test_disallowed_content_type_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/uploads/presign', [
            'purpose' => 'inventory_image', 'filename' => 'x', 'content_type' => 'application/x-msdownload', 'size' => 10,
        ], $this->headers())->assertStatus(422);
    }

    public function test_oversize_file_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/uploads/presign', [
            'purpose' => 'inventory_image', 'filename' => 'x', 'content_type' => 'image/png', 'size' => 999_999_999,
        ], $this->headers())->assertStatus(422);
    }

    public function test_unknown_purpose_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/uploads/presign', [
            'purpose' => 'malware', 'filename' => 'x', 'content_type' => 'image/png', 'size' => 10,
        ], $this->headers())->assertStatus(422);
    }

    public function test_attach_image_after_upload_then_read_and_delete(): void
    {
        $this->actingAsTenant($this->tenant);
        $item = InventoryItem::create(['name' => 'Wine', 'sku' => 'W', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $this->forgetTenant();

        $key = $this->presign();
        $this->store->store($key, 1000); // simulate the browser's completed upload

        $imageId = $this->postJson("/api/v1/inventory-items/{$item->getKey()}/images", [
            'key' => $key, 'content_type' => 'image/png', 'alt' => 'Front label',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.alt', 'Front label')
            ->json('data.id');

        $this->getJson("/api/v1/inventory-items/{$item->getKey()}/images", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', "https://bucket.example/{$key}?X-Amz-Signature=read");

        $this->deleteJson("/api/v1/inventory-items/{$item->getKey()}/images/{$imageId}", [], $this->headers())
            ->assertNoContent();
        $this->assertFalse($this->store->exists($key)); // object removed from bucket
    }

    public function test_attach_rejects_a_key_from_another_tenant(): void
    {
        $this->actingAsTenant($this->tenant);
        $item = InventoryItem::create(['name' => 'Wine', 'sku' => 'W', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $this->forgetTenant();

        $foreignKey = 'tenants/some-other-tenant/inventory/images/abc.png';
        $this->store->store($foreignKey, 1000);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/images", [
            'key' => $foreignKey, 'content_type' => 'image/png',
        ], $this->headers())->assertStatus(422);
    }

    public function test_attach_rejects_a_missing_object(): void
    {
        $this->actingAsTenant($this->tenant);
        $item = InventoryItem::create(['name' => 'Wine', 'sku' => 'W', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $this->forgetTenant();

        $key = $this->presign(); // presigned but never uploaded

        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/images", [
            'key' => $key, 'content_type' => 'image/png',
        ], $this->headers())->assertStatus(422);
    }
}
