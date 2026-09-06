<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Uploads\Contracts\ObjectStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Support\FakeObjectStore;
use Tests\TestCase;

/**
 * Product Detail's Images and Docs tabs — the Inertia counterpart of
 * tests/Feature/Uploads/PresignedUploadTest.php's own API coverage, through
 * Web\UploadController and Web\InventoryMediaController.
 */
class WebInventoryMediaTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private FakeObjectStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new FakeObjectStore;
        $this->app->instance(ObjectStore::class, $this->store);
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantAndAdmin(): array
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        return [$tenant, $admin];
    }

    private function makeItem(): InventoryItem
    {
        return InventoryItem::create(['name' => 'Wine', 'sku' => 'WINE', 'category' => 'FINISHED', 'unit' => 'bottles']);
    }

    private function presign(User $admin, Tenant $tenant, string $purpose, string $contentType): string
    {
        return $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->postJson('/uploads/presign', [
                'purpose' => $purpose, 'filename' => 'file', 'content_type' => $contentType, 'size' => 1000,
            ])
            ->assertOk()
            ->json('data.key');
    }

    public function test_presign_returns_a_tenant_scoped_url(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $prefix = 'tenants/'.$tenant->getKey().'/inventory/images/';

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->postJson('/uploads/presign', [
                'purpose' => 'inventory_image', 'filename' => 'front.png', 'content_type' => 'image/png', 'size' => 2048,
            ])
            ->assertOk()
            ->assertJsonPath('data.method', 'PUT');

        self::assertStringStartsWith($prefix, $response->json('data.key'));
    }

    public function test_show_carries_the_items_media_and_they_start_empty(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Show')
                ->has('images', 0)
                ->has('techSheets', 0)
                ->has('documents', 0));
    }

    public function test_image_can_be_attached_and_removed(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem();
        $this->forgetTenant();

        $key = $this->presign($admin, $tenant, 'inventory_image', 'image/png');
        $this->store->store($key, 1000);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/images', ['key' => $key, 'content_type' => 'image/png', 'alt' => 'Front label'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $imageId = null;
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$imageId, $key): void {
                $page->has('images', 1)
                    ->where('images.0.alt', 'Front label')
                    ->where('images.0.url', "https://bucket.example/{$key}?X-Amz-Signature=read");
                $imageId = $page->toArray()['props']['images'][0]['id'];
            });

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete('/inventory/'.$item->getKey().'/images/'.$imageId)
            ->assertRedirect()
            ->assertSessionHas('success');

        self::assertFalse($this->store->exists($key));

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page->has('images', 0));
    }

    public function test_attach_image_rejects_a_key_from_another_tenant(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem();
        $this->forgetTenant();

        $foreignKey = 'tenants/some-other-tenant/inventory/images/abc.png';
        $this->store->store($foreignKey, 1000);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/images', ['key' => $foreignKey, 'content_type' => 'image/png'])
            ->assertSessionHasErrors('key');
    }

    public function test_tech_sheet_can_be_attached_and_removed(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem();
        $this->forgetTenant();

        $key = $this->presign($admin, $tenant, 'inventory_tech_sheet', 'application/pdf');
        $this->store->store($key, 1000);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/tech-sheets', ['key' => $key, 'content_type' => 'application/pdf', 'name' => 'Spec sheet'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $sheetId = null;
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertInertia(function (AssertableInertia $page) use (&$sheetId): void {
                $page->has('techSheets', 1)->where('techSheets.0.name', 'Spec sheet');
                $sheetId = $page->toArray()['props']['techSheets'][0]['id'];
            });

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete('/inventory/'.$item->getKey().'/tech-sheets/'.$sheetId)
            ->assertRedirect()
            ->assertSessionHas('success');

        self::assertFalse($this->store->exists($key));
    }

    public function test_document_can_be_attached_and_removed(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem();
        $this->forgetTenant();

        $key = $this->presign($admin, $tenant, 'inventory_document', 'text/csv');
        $this->store->store($key, 1000);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/documents', ['key' => $key, 'content_type' => 'text/csv', 'name' => 'Import list'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $documentId = null;
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertInertia(function (AssertableInertia $page) use (&$documentId): void {
                $page->has('documents', 1)->where('documents.0.name', 'Import list');
                $documentId = $page->toArray()['props']['documents'][0]['id'];
            });

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete('/inventory/'.$item->getKey().'/documents/'.$documentId)
            ->assertRedirect()
            ->assertSessionHas('success');

        self::assertFalse($this->store->exists($key));
    }

    public function test_media_attach_endpoints_require_inventory_manage(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::WineClub]);

        $this->actingAsTenant($tenant);
        $item = $this->makeItem();
        $this->forgetTenant();

        $key = $this->presign($member, $tenant, 'inventory_image', 'image/png');
        $this->store->store($key, 1000);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/images', ['key' => $key, 'content_type' => 'image/png'])
            ->assertForbidden();
    }
}
