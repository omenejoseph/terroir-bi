<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Bulk Import — shared by the Inventory list, Analytics and Spend pages'
 * "Bulk Import" button. One CSV, matched against the catalog by SKU.
 */
class WebInventoryBulkImportTest extends TestCase
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

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('items.csv', $content);
    }

    public function test_creates_new_items_and_updates_existing_ones_by_sku(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        InventoryItem::create([
            'name' => 'Old name', 'sku' => 'EXIST-1', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '10',
        ]);
        $this->forgetTenant();

        $csv = "name,sku,category,group,unit,current_stock,min_stock,bottles_per_case,cost_per_unit,default_price\n"
            ."Renamed,EXIST-1,FINISHED,Wine,bottles,50,,,,\n"
            ."Brand New,NEW-1,RAW_MATERIAL,Packaging,units,100,10,,1.50,\n";

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/bulk-import', ['file' => $this->csv($csv)])
            ->assertRedirect()
            ->assertSessionHas('success', '1 item created · 1 item updated.');

        $this->actingAsTenant($tenant);
        $existing = InventoryItem::query()->where('sku', 'EXIST-1')->firstOrFail();
        self::assertSame('Renamed', $existing->name);
        self::assertSame('50.000', (string) $existing->current_stock);

        $new = InventoryItem::query()->where('sku', 'NEW-1')->firstOrFail();
        self::assertSame('Brand New', $new->name);
        self::assertSame('RAW_MATERIAL', $new->category->value);
        self::assertSame('100.000', (string) $new->current_stock);
        self::assertSame('10.000', (string) $new->min_stock);
        self::assertSame(150, $new->cost_per_unit?->getMinorAmount());
        $this->forgetTenant();
    }

    public function test_a_row_missing_its_sku_is_skipped_without_failing_the_rest(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $csv = "name,sku,category,unit\n"
            ."No SKU here,,FINISHED,bottles\n"
            ."Has SKU,SKU-OK,FINISHED,bottles\n";

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/bulk-import', ['file' => $this->csv($csv)])
            ->assertRedirect()
            ->assertSessionHas('success', '1 item created · 0 items updated. 1 row skipped: row 1: Missing SKU.');

        $this->actingAsTenant($tenant);
        self::assertSame(1, InventoryItem::query()->count());
        $this->forgetTenant();
    }

    public function test_a_new_row_missing_its_name_is_skipped(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $csv = "name,sku,category,unit\n,NEW-1,FINISHED,bottles\n";

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/bulk-import', ['file' => $this->csv($csv)])
            ->assertSessionHas('error', '0 items created · 0 items updated. 1 row skipped: row 1: Missing name for a new item.');

        $this->actingAsTenant($tenant);
        self::assertSame(0, InventoryItem::query()->count());
        $this->forgetTenant();
    }

    public function test_an_unrecognised_category_is_skipped(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $csv = "name,sku,category,unit\nItem,NEW-1,SPARKLING,bottles\n";

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/bulk-import', ['file' => $this->csv($csv)])
            ->assertSessionHas('error', '0 items created · 0 items updated. 1 row skipped: row 1: Unrecognised category "SPARKLING".');

        $this->actingAsTenant($tenant);
        self::assertSame(0, InventoryItem::query()->count());
        $this->forgetTenant();
    }

    public function test_a_sku_repeated_in_the_same_file_updates_in_place(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $csv = "name,sku,category,unit,current_stock\n"
            ."First pass,DUPE-1,FINISHED,bottles,10\n"
            ."Second pass,DUPE-1,FINISHED,bottles,20\n";

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/bulk-import', ['file' => $this->csv($csv)])
            ->assertSessionHas('success', '1 item created · 1 item updated.');

        $this->actingAsTenant($tenant);
        self::assertSame(1, InventoryItem::query()->count());
        $item = InventoryItem::query()->where('sku', 'DUPE-1')->firstOrFail();
        self::assertSame('Second pass', $item->name);
        self::assertSame('20.000', (string) $item->current_stock);
        $this->forgetTenant();
    }

    public function test_a_sku_repeated_three_times_in_the_same_file_is_created_once_and_updated_twice(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        // The in-loop $seen cache must keep tracking the row it wrote on pass
        // 1 through passes 2 and 3 too — not just a single repeat.
        $csv = "name,sku,category,unit,current_stock\n"
            ."First pass,DUPE-1,FINISHED,bottles,10\n"
            ."Second pass,DUPE-1,FINISHED,bottles,20\n"
            ."Third pass,DUPE-1,FINISHED,bottles,30\n";

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/bulk-import', ['file' => $this->csv($csv)])
            ->assertRedirect()
            ->assertSessionHas('success', '1 item created · 2 items updated.');

        $this->actingAsTenant($tenant);
        self::assertSame(1, InventoryItem::query()->count());
        $item = InventoryItem::query()->where('sku', 'DUPE-1')->firstOrFail();
        self::assertSame('Third pass', $item->name);
        self::assertSame('30.000', (string) $item->current_stock);
        $this->forgetTenant();
    }

    public function test_bulk_import_stops_at_the_row_ceiling_regardless_of_file_size(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        // MAX_ROWS is 5000: rows 1..5000 must import normally, row 5001 must
        // stop the loop with a report row rather than fail, and nothing past
        // it (rows 5002..5005 here) may be touched at all.
        $rows = [];
        for ($i = 1; $i <= 5005; $i++) {
            $rows[] = "Item {$i},SKU-{$i},FINISHED,bottles";
        }
        $csv = "name,sku,category,unit\n".implode("\n", $rows)."\n";

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/bulk-import', ['file' => $this->csv($csv)])
            ->assertRedirect()
            ->assertSessionHas(
                'success',
                '5000 items created · 0 items updated. 1 row skipped: row 5001: File exceeds the 5000-row limit — split it into smaller files.',
            );

        $this->actingAsTenant($tenant);
        self::assertSame(5000, InventoryItem::query()->count());
        self::assertNull(InventoryItem::query()->where('sku', 'SKU-5001')->first());
        self::assertNull(InventoryItem::query()->where('sku', 'SKU-5005')->first());
        $this->forgetTenant();
    }

    public function test_blank_columns_on_an_update_leave_the_existing_value_untouched(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        InventoryItem::create([
            'name' => 'Keep me', 'sku' => 'EXIST-1', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '10', 'min_stock' => '5',
        ]);
        $this->forgetTenant();

        // Only current_stock is filled in — name/min_stock must survive.
        $csv = "name,sku,category,unit,current_stock,min_stock\n,EXIST-1,,,99,\n";

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/bulk-import', ['file' => $this->csv($csv)]);

        $this->actingAsTenant($tenant);
        $item = InventoryItem::query()->where('sku', 'EXIST-1')->firstOrFail();
        self::assertSame('Keep me', $item->name);
        self::assertSame('99.000', (string) $item->current_stock);
        self::assertSame('5.000', (string) $item->min_stock);
        $this->forgetTenant();
    }

    public function test_bulk_import_requires_inventory_manage(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::WineClub]);

        $csv = "name,sku,category,unit\nItem,NEW-1,FINISHED,bottles\n";

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/bulk-import', ['file' => $this->csv($csv)])
            ->assertForbidden();
    }

    public function test_bulk_import_template_downloads_a_csv_with_the_expected_columns(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/bulk-import/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        self::assertStringStartsWith('name,sku,category,group,unit', $response->streamedContent());
    }
}
