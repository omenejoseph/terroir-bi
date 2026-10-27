<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\InventoryImage;
use App\Models\InventoryItem;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Queries\ListInventoryItemsQuery;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Categories as records (managed on the Inventory · Categories tab), the vintage chain between items,
 * and what the Inventory list and drag and drop do with both.
 */
class ProductCategoryTest extends TestCase
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
    }

    private function as(User $user, ?Tenant $tenant = null): static
    {
        return $this->actingAs($user)->withSession([ActiveTenantSession::KEY => ($tenant ?? $this->tenant)->getKey()]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function category(string $name, string $type = 'FINISHED', ?ProductCategory $parent = null, array $attributes = [], ?Tenant $tenant = null): ProductCategory
    {
        $this->actingAsTenant($tenant ?? $this->tenant);
        $category = ProductCategory::create([
            'name' => $name, 'type' => $type, 'parent_id' => $parent?->getKey(),
            ...$attributes,
        ]);
        $this->forgetTenant();

        return $category;
    }

    /** @param  array<string, mixed>  $attributes */
    private function item(string $name, ?ProductCategory $category = null, ?string $vintage = null, array $attributes = [], ?Tenant $tenant = null): InventoryItem
    {
        $this->actingAsTenant($tenant ?? $this->tenant);
        $item = InventoryItem::create([
            'name' => $name,
            'sku' => strtoupper(str_replace(' ', '-', $name)).'-'.($vintage ?? 'NV').'-'.substr(md5(uniqid()), 0, 4),
            'category' => $category?->type->value ?? 'FINISHED',
            'unit' => 'bottles',
            'current_stock' => '10',
            'vintage' => $vintage,
            'product_category_id' => $category?->getKey(),
            ...$attributes,
        ]);
        $this->forgetTenant();

        return $item;
    }

    /* ---- the Categories tab ------------------------------------------- */

    public function test_the_tab_lists_the_tree_with_item_counts(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $red = $this->category('Red', parent: $wine);
        $this->category('Closures', 'RAW_MATERIAL');
        $this->item('Plavac', $red, '2022');
        $this->item('Teran', $wine, '2023');

        $this->as($this->admin)->get('/inventory/categories')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Inventory/Categories')
            ->has('categories', 2)
            // Same position, so by name; the page splits them by type.
            ->where('categories.0.name', 'Closures')
            ->where('categories.0.type', 'RAW_MATERIAL')
            ->where('categories.1.name', 'Wine')
            ->where('categories.1.has_vintage', true)
            // The parent's count covers its own items and its subcategories'.
            ->where('categories.1.items_count', 2)
            ->where('categories.1.children.0.name', 'Red')
            ->where('categories.1.children.0.items_count', 1));
    }

    public function test_a_category_and_a_subcategory_can_be_created(): void
    {
        $this->as($this->admin)->post('/inventory/categories', ['name' => 'Wine', 'type' => 'FINISHED', 'has_vintage' => true])->assertRedirect();

        $wine = ProductCategory::query()->withoutGlobalScopes()->where('name', 'Wine')->firstOrFail();
        $this->assertTrue($wine->has_vintage);
        $this->assertSame('FINISHED', $wine->type->value);

        // A subcategory takes its parent's type, and starts out like its parent.
        $this->as($this->admin)->post('/inventory/categories', ['name' => 'Red', 'parent_id' => $wine->getKey()])->assertRedirect();

        $red = ProductCategory::query()->withoutGlobalScopes()->where('name', 'Red')->firstOrFail();
        $this->assertSame($wine->getKey(), $red->parent_id);
        $this->assertSame('FINISHED', $red->type->value);
        $this->assertTrue($red->has_vintage);
    }

    public function test_names_must_be_unique_among_siblings_whatever_the_case(): void
    {
        $wine = $this->category('Wine');

        $this->as($this->admin)->post('/inventory/categories', ['name' => 'wine', 'type' => 'FINISHED'])->assertSessionHasErrors('name');
        // The same name under another type, or as a subcategory, is a different place.
        $this->as($this->admin)->post('/inventory/categories', ['name' => 'Wine', 'type' => 'RAW_MATERIAL'])->assertSessionDoesntHaveErrors();
        $this->as($this->admin)->post('/inventory/categories', ['name' => 'Wine', 'parent_id' => $wine->getKey()])->assertSessionDoesntHaveErrors();
    }

    public function test_a_subcategory_cannot_have_subcategories_of_its_own(): void
    {
        $wine = $this->category('Wine');
        $red = $this->category('Red', parent: $wine);

        $this->as($this->admin)->post('/inventory/categories', ['name' => 'Deep', 'parent_id' => $red->getKey()])->assertSessionHasErrors('parent_id');
    }

    public function test_renaming_rewrites_the_group_text_on_its_items(): void
    {
        $wine = $this->category('Wine');
        $red = $this->category('Red', parent: $wine);
        $item = $this->item('Plavac', $red, '2022');
        $this->assertSame('Wine', $item->refresh()->group);
        $this->assertSame('Red', $item->subcategory);

        $this->as($this->admin)->patch("/inventory/categories/{$wine->getKey()}", ['name' => 'Wines'])->assertRedirect();
        $this->as($this->admin)->patch("/inventory/categories/{$red->getKey()}", ['name' => 'Reds'])->assertRedirect();

        $item->refresh();
        $this->assertSame('Wines', $item->group);
        $this->assertSame('Reds', $item->subcategory);
    }

    public function test_switching_vintages_off_unlinks_the_chains_in_the_category(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $old = $this->item('Plavac', $wine, '2021');
        $new = $this->item('Plavac', $wine, '2022', ['previous_vintage_id' => $old->getKey()]);

        $this->as($this->admin)->patch("/inventory/categories/{$wine->getKey()}", ['has_vintage' => false])->assertRedirect();

        $this->assertNull($new->refresh()->previous_vintage_id);
        // The vintage values themselves are kept.
        $this->assertSame('2022', $new->vintage);
    }

    public function test_a_category_holding_inventory_cannot_be_deleted(): void
    {
        $wine = $this->category('Wine');
        $red = $this->category('Red', parent: $wine);
        $this->item('Plavac', $red);

        // Not the subcategory, and not its parent either — the parent's subcategories count too.
        $this->as($this->admin)->delete("/inventory/categories/{$red->getKey()}")->assertSessionHasErrors('category');
        $this->as($this->admin)->delete("/inventory/categories/{$wine->getKey()}")->assertSessionHasErrors('category');

        $this->assertSame(2, ProductCategory::query()->withoutGlobalScopes()->count());
    }

    public function test_an_empty_category_is_deleted_along_with_its_empty_subcategories(): void
    {
        $wine = $this->category('Wine');
        $this->category('Red', parent: $wine);

        $this->as($this->admin)->delete("/inventory/categories/{$wine->getKey()}")->assertRedirect();

        $this->assertSame(0, ProductCategory::query()->withoutGlobalScopes()->count());
    }

    public function test_categories_can_be_reordered(): void
    {
        $a = $this->category('Alpha');
        $b = $this->category('Bravo');

        $this->as($this->admin)->post('/inventory/categories/reorder', ['ids' => [$b->getKey(), $a->getKey()]])->assertRedirect();

        $this->as($this->admin)->get('/inventory/categories')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('categories.0.name', 'Bravo')
            ->where('categories.1.name', 'Alpha'));
    }

    public function test_only_members_who_can_manage_inventory_may_change_categories(): void
    {
        $cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $wine = $this->category('Wine');

        $this->as($cellar)->post('/inventory/categories', ['name' => 'X', 'type' => 'FINISHED'])->assertForbidden();
        $this->as($cellar)->patch("/inventory/categories/{$wine->getKey()}", ['name' => 'Y'])->assertForbidden();
        $this->as($cellar)->delete("/inventory/categories/{$wine->getKey()}")->assertForbidden();
    }

    public function test_categories_are_per_organisation(): void
    {
        $other = $this->createTenant();
        $theirs = $this->category('Theirs', tenant: $other);

        $this->as($this->admin)->get('/inventory/categories')->assertInertia(fn (AssertableInertia $page) => $page->has('categories', 0));
        $this->as($this->admin)->patch("/inventory/categories/{$theirs->getKey()}", ['name' => 'Mine'])->assertNotFound();
    }

    /* ---- items and their category -------------------------------------- */

    public function test_choosing_a_category_fills_the_group_and_subcategory_text(): void
    {
        $wine = $this->category('Wine');
        $red = $this->category('Red', parent: $wine);

        $this->as($this->admin)->post('/inventory', [
            'name' => 'Plavac', 'sku' => 'PL-1', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'bottles_per_case' => 6, 'product_category_id' => $red->getKey(),
        ])->assertRedirect();

        $item = InventoryItem::query()->withoutGlobalScopes()->where('sku', 'PL-1')->firstOrFail();
        $this->assertSame($red->getKey(), $item->product_category_id);
        $this->assertSame('Wine', $item->group);
        $this->assertSame('Red', $item->subcategory);
    }

    public function test_naming_a_group_as_text_finds_or_creates_the_category(): void
    {
        $this->category('Wine');
        $this->actingAsTenant($this->tenant);
        $a = InventoryItem::create(['name' => 'A', 'sku' => 'A', 'category' => 'FINISHED', 'unit' => 'bottles', 'group' => 'wine', 'subcategory' => 'Rosé']);
        $b = InventoryItem::create(['name' => 'B', 'sku' => 'B', 'category' => 'FINISHED', 'unit' => 'bottles', 'group' => 'Wine', 'subcategory' => 'Rosé']);
        $this->forgetTenant();

        // The existing "Wine" is reused (case-insensitively), "Rosé" is made once under it.
        $this->assertSame($a->product_category_id, $b->product_category_id);
        $this->assertSame('Wine', $a->group);
        $this->assertSame(2, ProductCategory::query()->withoutGlobalScopes()->count());
    }

    public function test_an_item_cannot_be_filed_under_another_types_category(): void
    {
        $closures = $this->category('Closures', 'RAW_MATERIAL');

        $this->as($this->admin)->post('/inventory', [
            'name' => 'Plavac', 'sku' => 'PL-1', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'bottles_per_case' => 6, 'product_category_id' => $closures->getKey(),
        ])->assertSessionHasErrors('product_category_id');
    }

    public function test_an_item_cannot_be_filed_under_another_organisations_category(): void
    {
        $theirs = $this->category('Theirs', tenant: $this->createTenant());
        $item = $this->item('Plavac');

        $this->as($this->admin)->patch("/inventory/{$item->getKey()}", ['product_category_id' => $theirs->getKey()])
            ->assertSessionHasErrors('product_category_id');
    }

    /* ---- the vintage chain ---------------------------------------------- */

    public function test_a_new_vintage_can_name_the_previous_one(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $old = $this->item('Plavac', $wine, '2021');

        $this->as($this->admin)->post('/inventory', [
            'name' => 'Plavac', 'sku' => 'PL-22', 'category' => 'FINISHED', 'unit' => 'bottles', 'sales_unit' => 'bottles',
            'bottles_per_case' => 6, 'product_category_id' => $wine->getKey(), 'vintage' => '2022', 'previous_vintage_id' => $old->getKey(),
        ])->assertRedirect();

        $new = InventoryItem::query()->withoutGlobalScopes()->where('sku', 'PL-22')->firstOrFail();
        $this->assertSame($old->getKey(), $new->previous_vintage_id);
    }

    public function test_the_previous_vintage_must_be_sensible(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $a = $this->item('Plavac', $wine, '2021');
        $b = $this->item('Plavac', $wine, '2022', ['previous_vintage_id' => $a->getKey()]);
        $c = $this->item('Plavac', $wine, '2023');
        $foreign = $this->item('Other', null, '2020', tenant: $this->createTenant());

        // Itself.
        $this->as($this->admin)->patch("/inventory/{$c->getKey()}", ['previous_vintage_id' => $c->getKey()])->assertSessionHasErrors('previous_vintage_id');
        // Already followed by another vintage (2021 is the predecessor of 2022).
        $this->as($this->admin)->patch("/inventory/{$c->getKey()}", ['previous_vintage_id' => $a->getKey()])->assertSessionHasErrors('previous_vintage_id');
        // A loop: 2021 following 2022 while 2022 follows 2021.
        $this->as($this->admin)->patch("/inventory/{$a->getKey()}", ['previous_vintage_id' => $b->getKey()])->assertSessionHasErrors('previous_vintage_id');
        // Someone else's product.
        $this->as($this->admin)->patch("/inventory/{$c->getKey()}", ['previous_vintage_id' => $foreign->getKey()])->assertSessionHasErrors('previous_vintage_id');
        // Re-saving the link it already has is fine, and so is the real next step.
        $this->as($this->admin)->patch("/inventory/{$b->getKey()}", ['previous_vintage_id' => $a->getKey()])->assertSessionDoesntHaveErrors();
        $this->as($this->admin)->patch("/inventory/{$c->getKey()}", ['previous_vintage_id' => $b->getKey()])->assertSessionDoesntHaveErrors();
        $this->assertSame($b->getKey(), $c->refresh()->previous_vintage_id);
    }

    public function test_the_form_options_offer_vintage_products_that_are_not_already_followed(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $closures = $this->category('Closures', 'RAW_MATERIAL');
        $a = $this->item('Plavac', $wine, '2021');
        $this->item('Plavac', $wine, '2022', ['previous_vintage_id' => $a->getKey()]);
        $this->item('Cork', $closures);

        $this->as($this->admin)
            ->get('/inventory', $this->inertiaPartial('Inventory/Index', ['vintageOptions', 'productCategories']))
            ->assertOk()
            ->assertJsonCount(2, 'props.vintageOptions')
            ->assertJsonPath('props.vintageOptions.0.has_next', true)
            ->assertJsonPath('props.vintageOptions.1.has_next', false)
            ->assertJsonCount(2, 'props.productCategories');
    }

    /* ---- the list --------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function index(string $query = ''): array
    {
        $props = [];
        $this->as($this->admin)->get('/inventory'.$query)->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    public function test_the_list_shows_only_the_latest_vintage_with_the_older_ones_beneath(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $v21 = $this->item('Sangreal Shiraz', $wine, '2021', ['current_stock' => '0']);
        $v23 = $this->item('Sangreal Shiraz', $wine, '2023', ['previous_vintage_id' => $v21->getKey(), 'current_stock' => '1500']);
        $v24 = $this->item('Sangreal Shiraz', $wine, '2024', ['previous_vintage_id' => $v23->getKey(), 'current_stock' => '3000']);
        $this->item('Teran', $wine, '2022');

        $props = $this->index();

        $this->assertSame(['Sangreal Shiraz', 'Teran'], array_column($props['items']['data'], 'name'));
        $this->assertSame(2, $props['items']['meta']['total']);

        $head = $props['items']['data'][0];
        $this->assertSame($v24->getKey(), $head['id']);
        $this->assertSame('2024', $head['vintage']);
        // Newest first beneath the head.
        $this->assertSame(['2023', '2021'], array_column($head['previous_vintages'], 'vintage'));
        $this->assertSame('1500.000', $head['previous_vintages'][0]['current_stock']);
        $this->assertSame([], $props['items']['data'][1]['previous_vintages']);
        // The totals still count every vintage's stock once.
        $this->assertSame('4510.000', $props['totals']['on_hand']);
    }

    public function test_searching_for_an_older_vintage_brings_up_its_product(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $old = $this->item('Plavac', $wine, '2019');
        $this->item('Plavac', $wine, '2022', ['previous_vintage_id' => $old->getKey()]);
        $this->item('Teran', $wine, '2022');

        $props = $this->index('?search=2019');
        $this->assertSame('2022', $props['items']['data'][0]['vintage']);
        $this->assertSame('Plavac', $props['items']['data'][0]['name']);
        $this->assertCount(1, $props['items']['data']);

        $props = $this->index('?search=Teran');
        $this->assertSame(['Teran'], array_column($props['items']['data'], 'name'));
    }

    public function test_the_api_listing_still_returns_every_vintage(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $old = $this->item('Plavac', $wine, '2019');
        $this->item('Plavac', $wine, '2022', ['previous_vintage_id' => $old->getKey()]);

        $this->actingAsTenant($this->tenant);
        $this->assertSame(2, app(ListInventoryItemsQuery::class)->paginate([], 25)->total());
        $this->assertSame(1, app(ListInventoryItemsQuery::class)->paginateProducts([], 25)->total());
    }

    public function test_a_new_vintage_shows_the_photo_of_the_one_before(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $old = $this->item('Plavac', $wine, '2021');
        $new = $this->item('Plavac', $wine, '2022', ['previous_vintage_id' => $old->getKey()]);
        $this->actingAsTenant($this->tenant);
        InventoryImage::create([
            'inventory_item_id' => $old->getKey(), 'object_key' => 'tenants/'.$this->tenant->getKey().'/inventory/plavac.jpg',
            'content_type' => 'image/jpeg', 'size_bytes' => 10, 'sort_order' => 0,
        ]);
        $this->forgetTenant();

        $head = $this->index()['items']['data'][0];
        $this->assertSame($new->getKey(), $head['id']);
        $this->assertIsString($head['image_url']);
        $this->assertStringContainsString('plavac.jpg', $head['image_url']);
    }

    /* ---- drag and drop between categories ------------------------------------ */

    public function test_dragging_a_product_to_another_category_takes_all_its_vintages_with_it(): void
    {
        $wine = $this->category('Wine', attributes: ['has_vintage' => true]);
        $spirits = $this->category('Spirits', attributes: ['has_vintage' => true]);
        $old = $this->item('Plavac', $wine, '2021');
        $new = $this->item('Plavac', $wine, '2022', ['previous_vintage_id' => $old->getKey()]);
        $stay = $this->item('Teran', $wine, '2022');

        $this->as($this->admin)->postJson('/inventory-move', ['ids' => [$new->getKey()], 'product_category_id' => $spirits->getKey()])->assertNoContent();

        $this->assertSame($spirits->getKey(), $old->refresh()->product_category_id);
        $this->assertSame($spirits->getKey(), $new->refresh()->product_category_id);
        $this->assertSame('Spirits', $new->group);
        $this->assertSame($wine->getKey(), $stay->refresh()->product_category_id);
        // The chain survives the move.
        $this->assertSame($old->getKey(), $new->previous_vintage_id);
    }

    public function test_a_product_is_not_dropped_onto_another_types_category(): void
    {
        $wine = $this->category('Wine');
        $closures = $this->category('Closures', 'RAW_MATERIAL');
        $item = $this->item('Plavac', $wine);

        $this->as($this->admin)->postJson('/inventory-move', ['ids' => [$item->getKey()], 'product_category_id' => $closures->getKey()])->assertNoContent();

        $this->assertSame($wine->getKey(), $item->refresh()->product_category_id);
    }

    public function test_only_members_who_can_manage_inventory_may_move_products(): void
    {
        $cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $wine = $this->category('Wine');

        $this->as($cellar)->postJson('/inventory-move', ['ids' => ['x'], 'product_category_id' => $wine->getKey()])->assertForbidden();
    }
}
