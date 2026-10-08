<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Concerns\LegacyFixture;
use Tests\TestCase;

class CustomerCategoriesStepTest extends TestCase
{
    use InteractsWithTenancy;
    use LegacyFixture;
    use RefreshDatabase;

    private const STEPS = ['pricing_tiers', 'customers', 'customer_categories'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLegacySchema();

        foreach ([['k1', 'Restaurant', 1], ['k2', 'Hotel', 2], ['k3', 'Private customer', 3]] as [$id, $name, $order]) {
            $this->legacyInsert('CustomerCategory', ['id' => $id, 'name' => $name, 'sortOrder' => $order, 'isActive' => 't', 'createdAt' => '2026-05-28 08:38:00']);
        }
    }

    /** @param array<string, mixed> $o */
    private function customer(string $id, array $o = []): void
    {
        $this->legacyInsert('Customer', $o + [
            'id' => $id, 'companyName' => "Co {$id}", 'email' => "{$id}@x.hr", 'isActive' => 't', 'rebatePercent' => 0,
            'excludeFromStats' => 'f', 'hidePrices' => 'f', 'isAgency' => 'f', 'allowSingleBottle' => 'f',
            'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01',
        ]);
    }

    private function run_(?ImportContext $previous = null): ImportContext
    {
        if ($previous === null) {
            $tenant = $this->createTenant();
            $ids = new IdMap($tenant->getKey());
        } else {
            $tenant = $previous->tenant;
            $ids = $previous->ids;
        }

        $ctx = new ImportContext($tenant, DB::connection('legacy'), $ids, new Report);
        app(LegacyImporter::class)->run($ctx, self::STEPS);

        return $ctx;
    }

    private function categoryOf(string $company): ?string
    {
        return DB::table('customers as c')->leftJoin('customer_categories as k', 'k.id', '=', 'c.customer_category_id')
            ->where('c.company_name', $company)->value('k.name');
    }

    public function test_categories_keep_their_order_and_customers_keep_their_wording(): void
    {
        $this->customer('c1', ['customerType' => 'Restaurant']);
        $this->customer('c2', ['customerType' => 'hotel ']);   // case/space differences still match
        $this->customer('c3');                                  // no label in legacy

        $ctx = $this->run_();

        $this->assertSame(['Restaurant', 'Hotel', 'Private customer'], DB::table('customer_categories')->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame('Restaurant', $this->categoryOf('Co c1'));
        $this->assertSame('Hotel', $this->categoryOf('Co c2'));
        $this->assertNull($this->categoryOf('Co c3'));
        $this->assertSame([3, 2], [$ctx->report->counts()['customer_categories']['written'], $ctx->report->counts()['customer_category_labels']['written']]);
    }

    public function test_the_fixed_sales_channel_is_untouched(): void
    {
        $this->customer('c1', ['customerType' => 'Restaurant']);

        $this->run_();

        // Wording lives in the category; the channel keeps coming from the same mapping rules as before.
        $this->assertSame('WHOLESALE', DB::table('customers')->value('customer_type'));
    }

    public function test_a_label_missing_from_the_list_is_kept_by_creating_the_category(): void
    {
        $this->customer('c1', ['customerType' => 'Vinoteka']);

        $ctx = $this->run_();

        $this->assertSame('Vinoteka', $this->categoryOf('Co c1'));
        $this->assertSame(4, DB::table('customer_categories')->count());
        $this->assertNotEmpty(array_filter($ctx->report->warnings(), fn ($w) => str_contains($w, "'Vinoteka' was not on the category list")));
    }

    public function test_rerunning_is_idempotent_and_never_overwrites_a_label_staff_chose(): void
    {
        $this->customer('c1', ['customerType' => 'Restaurant']);
        $ctx = $this->run_();

        // Staff re-label the customer in the new app, then the step runs again.
        $hotel = (string) DB::table('customer_categories')->where('name', 'Hotel')->value('id');
        DB::table('customers')->update(['customer_category_id' => $hotel]);

        $this->run_($ctx);

        $this->assertSame('Hotel', $this->categoryOf('Co c1'));
        $this->assertSame(3, DB::table('customer_categories')->count());
    }

    public function test_an_existing_category_with_the_same_name_is_adopted_not_duplicated(): void
    {
        $tenant = $this->createTenant();
        DB::table('customer_categories')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->getKey(), 'name' => 'RESTAURANT', 'sort_order' => 9, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->customer('c1', ['customerType' => 'Restaurant']);

        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx, self::STEPS);

        $this->assertSame(3, DB::table('customer_categories')->count()); // 2 new + the hand-made one
        $this->assertSame('RESTAURANT', $this->categoryOf('Co c1'));
    }
}
