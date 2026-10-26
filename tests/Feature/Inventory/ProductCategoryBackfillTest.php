<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Inventory\ProductCategoryBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The migration that ports existing inventory (free-text group / subcategory, vintages as separate
 * items of the same name) to the category + vintage-chain model. Rows are inserted straight into the
 * table — as they stood before the port — so none of the model's own category handling touches them.
 */
class ProductCategoryBackfillTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
    }

    /** @param  array<string, mixed>  $attributes */
    private function legacy(string $name, ?string $group, ?string $sub = null, ?string $vintage = null, array $attributes = [], ?Tenant $tenant = null): string
    {
        $id = (string) Str::ulid();

        DB::table('inventory_items')->insert([
            'id' => $id,
            'tenant_id' => ($tenant ?? $this->tenant)->getKey(),
            'name' => $name,
            'sku' => $name.'-'.$vintage.'-'.$id,
            'category' => 'FINISHED',
            'group' => $group,
            'subcategory' => $sub,
            'vintage' => $vintage,
            'unit' => 'bottles',
            'unit_size' => '750 ml',
            'created_at' => now()->addSeconds(random_int(0, 1000)),
            'updated_at' => now(),
            ...$attributes,
        ]);

        return $id;
    }

    private function item(string $id): stdClass
    {
        $row = DB::table('inventory_items')->where('id', $id)->first();
        assert($row instanceof stdClass);

        return $row;
    }

    /** @return array<string, stdClass> name => row, for one tenant */
    private function categories(?Tenant $tenant = null): array
    {
        $rows = DB::table('product_categories')->where('tenant_id', ($tenant ?? $this->tenant)->getKey())->get();
        $out = [];
        foreach ($rows as $row) {
            $out[($row->parent_id !== null ? $rows->firstWhere('id', $row->parent_id)?->name.'/' : '').$row->name] = $row;
        }

        return $out;
    }

    public function test_groups_and_subcategories_become_categories_and_items_are_linked(): void
    {
        $red = $this->legacy('Plavac', 'Wine', 'Red');
        $white = $this->legacy('Malvazija', 'Wine', 'White');
        $loose = $this->legacy('Rakija', 'Spirits');
        $bare = $this->legacy('Gift box', null);

        $report = (new ProductCategoryBackfill)->run();

        $cats = $this->categories();
        $this->assertSame(['Spirits', 'Wine', 'Wine/Red', 'Wine/White'], collect(array_keys($cats))->sort()->values()->all());
        $this->assertNull($cats['Wine']->parent_id);
        $this->assertSame($cats['Wine']->id, $cats['Wine/Red']->parent_id);
        $this->assertSame('FINISHED', $cats['Wine/Red']->type);

        $this->assertSame($cats['Wine/Red']->id, $this->item($red)->product_category_id);
        $this->assertSame($cats['Wine/White']->id, $this->item($white)->product_category_id);
        $this->assertSame($cats['Spirits']->id, $this->item($loose)->product_category_id);
        // No group, no category — it stays "Ungrouped".
        $this->assertNull($this->item($bare)->product_category_id);

        $this->assertSame(4, $report['categories']);
        $this->assertSame(3, $report['linked']);
    }

    public function test_the_order_the_organisation_arranged_is_kept(): void
    {
        $this->legacy('Plavac', 'Wine', 'Red');
        $this->legacy('Malvazija', 'Wine', 'White');
        $this->legacy('Rakija', 'Spirits');
        $this->legacy('Olive oil', 'Oil');

        foreach ([['Wine', '', 1], ['Spirits', '', 0], ['Wine', 'White', 0], ['Wine', 'Red', 1]] as [$group, $sub, $order]) {
            DB::table('inventory_group_orders')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenant->getKey(), 'category' => 'FINISHED',
                'group_name' => $group, 'subcategory' => $sub, 'sort_order' => $order,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        (new ProductCategoryBackfill)->run();

        $top = DB::table('product_categories')->whereNull('parent_id')->orderBy('sort_order')->pluck('name')->all();
        // Arranged ones in their saved order (Spirits, Wine), the unarranged one after them.
        $this->assertSame(['Spirits', 'Wine', 'Oil'], $top);

        $cats = $this->categories();
        $this->assertLessThan($cats['Wine/Red']->sort_order, $cats['Wine/White']->sort_order);
    }

    public function test_group_text_that_differs_only_by_case_or_spacing_becomes_one_category(): void
    {
        // Seen in this order, so the first spelling names the category.
        $a = $this->legacy('Plavac', 'Wine', 'Red', null, ['created_at' => now()->subDay()]);
        $b = $this->legacy('Teran', ' wine ', 'red', null, ['created_at' => now()]);

        (new ProductCategoryBackfill)->run();

        $this->assertCount(2, $this->categories());
        $this->assertSame($this->item($a)->product_category_id, $this->item($b)->product_category_id);
        // The item's text is normalised to the category's own name.
        $this->assertSame('Wine', $this->item($b)->group);
        $this->assertSame('Red', $this->item($b)->subcategory);
    }

    public function test_a_category_carrying_vintages_is_flagged(): void
    {
        $this->legacy('Plavac', 'Wine', 'Red', '2021');
        $this->legacy('Rakija', 'Spirits');
        $this->legacy('Cork', 'Closures', null, null, ['category' => 'RAW_MATERIAL']);

        (new ProductCategoryBackfill)->run();

        $cats = $this->categories();
        $this->assertTrue((bool) $cats['Wine/Red']->has_vintage);
        // The parent carries them too, since its product sits under it.
        $this->assertTrue((bool) $cats['Wine']->has_vintage);
        $this->assertFalse((bool) $cats['Spirits']->has_vintage);
        $this->assertFalse((bool) $cats['Closures']->has_vintage);
    }

    public function test_the_same_name_in_different_types_stays_in_separate_categories(): void
    {
        $this->legacy('Plavac', 'Wine');
        $this->legacy('Grapes', 'Wine', null, null, ['category' => 'RAW_MATERIAL']);

        (new ProductCategoryBackfill)->run();

        $this->assertSame(['FINISHED', 'RAW_MATERIAL'], DB::table('product_categories')->where('name', 'Wine')->orderBy('type')->pluck('type')->all());
    }

    public function test_a_wines_vintages_are_chained_oldest_to_newest(): void
    {
        // Created out of order on purpose: the chain follows the year, not the insert order.
        $v23 = $this->legacy('Sangreal Shiraz', 'Wine', 'Red', '2023');
        $v21 = $this->legacy('Sangreal Shiraz', 'Wine', 'Red', '2021');
        $v25 = $this->legacy('Sangreal Shiraz', 'Wine', 'Red', '2025');
        $v24 = $this->legacy('Sangreal Shiraz', 'Wine', 'Red', '2024');

        $report = (new ProductCategoryBackfill)->run();

        $this->assertNull($this->item($v21)->previous_vintage_id);
        $this->assertSame($v21, $this->item($v23)->previous_vintage_id);
        $this->assertSame($v23, $this->item($v24)->previous_vintage_id);
        $this->assertSame($v24, $this->item($v25)->previous_vintage_id);
        $this->assertSame(3, $report['chained']);
    }

    public function test_only_the_same_wine_is_chained(): void
    {
        // Same name, different bottle size; same name, different category; different name.
        $std = $this->legacy('Plavac', 'Wine', 'Red', '2021');
        $magnum = $this->legacy('Plavac', 'Wine', 'Red', '2022', ['unit_size' => '1.5 l']);
        $otherCategory = $this->legacy('Plavac', 'Wine', 'White', '2022');
        $other = $this->legacy('Teran', 'Wine', 'Red', '2022');

        $report = (new ProductCategoryBackfill)->run();

        foreach ([$std, $magnum, $otherCategory, $other] as $id) {
            $this->assertNull($this->item($id)->previous_vintage_id);
        }
        $this->assertSame(0, $report['chained']);
    }

    public function test_duplicates_and_non_year_vintages_are_left_unlinked(): void
    {
        $first = $this->legacy('Plavac', 'Wine', 'Red', '2021', ['created_at' => now()->subDays(3)]);
        $duplicate = $this->legacy('Plavac', 'Wine', 'Red', '2021', ['created_at' => now()->subDay()]);
        $next = $this->legacy('Plavac', 'Wine', 'Red', '2022');
        $nv = $this->legacy('Plavac', 'Wine', 'Red', 'NV');

        (new ProductCategoryBackfill)->run();

        // The first 2021 keeps the slot; its twin is not chained, and neither is the non-vintage.
        $this->assertSame($first, $this->item($next)->previous_vintage_id);
        $this->assertNull($this->item($duplicate)->previous_vintage_id);
        $this->assertNull($this->item($nv)->previous_vintage_id);
        $this->assertNull($this->item($first)->previous_vintage_id);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->legacy('Plavac', 'Wine', 'Red', '2021');
        $this->legacy('Plavac', 'Wine', 'Red', '2022');

        (new ProductCategoryBackfill)->run();
        $before = [DB::table('product_categories')->count(), DB::table('inventory_items')->whereNotNull('previous_vintage_id')->count()];

        $report = (new ProductCategoryBackfill)->run();

        $this->assertSame($before, [DB::table('product_categories')->count(), DB::table('inventory_items')->whereNotNull('previous_vintage_id')->count()]);
        $this->assertSame(['categories' => 0, 'linked' => 0, 'chained' => 0], $report);
    }

    public function test_an_existing_category_is_reused_not_duplicated(): void
    {
        DB::table('product_categories')->insert([
            'id' => $existing = (string) Str::ulid(), 'tenant_id' => $this->tenant->getKey(), 'type' => 'FINISHED',
            'name' => 'Wine', 'has_vintage' => false, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = $this->legacy('Plavac', 'wine');

        (new ProductCategoryBackfill)->run();

        $this->assertSame(1, DB::table('product_categories')->count());
        $this->assertSame($existing, $this->item($item)->product_category_id);
    }

    public function test_each_organisation_gets_its_own_categories(): void
    {
        $other = $this->createTenant();
        $mine = $this->legacy('Plavac', 'Wine');
        $theirs = $this->legacy('Plavac', 'Wine', tenant: $other);

        (new ProductCategoryBackfill)->run();

        $this->assertSame(2, DB::table('product_categories')->count());
        $this->assertNotSame($this->item($mine)->product_category_id, $this->item($theirs)->product_category_id);
        $this->assertSame($this->tenant->getKey(), DB::table('product_categories')->where('id', $this->item($mine)->product_category_id)->value('tenant_id'));
        $this->assertSame($other->getKey(), DB::table('product_categories')->where('id', $this->item($theirs)->product_category_id)->value('tenant_id'));
    }

    public function test_it_can_be_limited_to_one_organisation(): void
    {
        $other = $this->createTenant();
        $mine = $this->legacy('Plavac', 'Wine');
        $theirs = $this->legacy('Plavac', 'Wine', tenant: $other);

        (new ProductCategoryBackfill)->run($this->tenant->getKey());

        $this->assertNotNull($this->item($mine)->product_category_id);
        $this->assertNull($this->item($theirs)->product_category_id);
    }

    public function test_the_migration_itself_runs_the_port(): void
    {
        $item = $this->legacy('Plavac', 'Wine', 'Red', '2021');
        $newer = $this->legacy('Plavac', 'Wine', 'Red', '2022');

        $migration = require database_path('migrations/2026_10_20_000002_port_inventory_to_product_categories.php');
        $migration->up();

        $this->assertNotNull($this->item($item)->product_category_id);
        $this->assertSame($item, $this->item($newer)->previous_vintage_id);
    }

    public function test_a_ported_catalogue_lists_as_products_with_vintages_beneath(): void
    {
        $admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->legacy('Sangreal Shiraz', 'Wine', 'Red', '2021', ['current_stock' => 0]);
        $this->legacy('Sangreal Shiraz', 'Wine', 'Red', '2023', ['current_stock' => 1500]);
        $this->legacy('Sangreal Shiraz', 'Wine', 'Red', '2024', ['current_stock' => 3000]);
        $this->legacy('Teran', 'Wine', 'Red', '2022');

        (new ProductCategoryBackfill)->run();

        $this->actingAs($admin)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->get('/inventory')
            ->assertInertia(function ($page) {
                $items = $page->toArray()['props']['items']['data'];
                $this->assertSame(['Sangreal Shiraz', 'Teran'], array_column($items, 'name'));
                $this->assertSame('2024', $items[0]['vintage']);
                $this->assertSame(['2023', '2021'], array_column($items[0]['previous_vintages'], 'vintage'));
            });
    }
}
