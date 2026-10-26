<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\CellarProcess;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WineLot;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** The Cellar tables that grow — Lots list, Cellar book, the home's bottled archive — page on the server. */
class WebCellarPaginationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return TestResponse<Response> */
    private function visit(string $uri)
    {
        return $this->actingAs($this->cellar)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])->get($uri);
    }

    private function lot(int $i, string $status = 'AGING', string $volume = '100.000'): WineLot
    {
        return WineLot::create([
            'lot_number' => sprintf('L-%03d', $i), 'name' => sprintf('Wine %03d', $i), 'grape_variety' => 'Merlot', 'vintage' => (string) (2000 + $i % 20),
            'wine_type' => 'RED', 'status' => $status, 'initial_volume' => $volume, 'current_volume' => $volume,
        ]);
    }

    public function test_the_lots_list_pages_and_its_totals_cover_every_matching_lot(): void
    {
        $this->actingAsTenant($this->tenant);
        for ($i = 1; $i <= 30; $i++) {
            $this->lot($i);
        }
        $this->lot(99, 'BOTTLED', '0.000');
        $this->forgetTenant();

        $this->visit('/cellar/lots?per_page=10&page=2')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('lots.data', 10)
            ->where('lots.meta.total', 31)
            ->where('lots.meta.last_page', 4)
            ->where('lots.meta.current_page', 2)
            // The header figures describe all 31 lots (30 × 100 L), not the ten on this page.
            ->where('totals.count', 31)
            ->where('totals.volume', '3000.000'));

        // A filter narrows the pages and the totals together.
        $this->visit('/cellar/lots?status=BOTTLED')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('lots.data', 1)->where('totals.count', 1)->where('totals.volume', '0.000'));
    }

    public function test_the_lots_list_search_runs_over_every_lot_not_the_page(): void
    {
        $this->actingAsTenant($this->tenant);
        for ($i = 1; $i <= 30; $i++) {
            $this->lot($i);
        }
        WineLot::create(['lot_number' => 'ZZ-1', 'name' => 'Zzz Needle', 'grape_variety' => 'Pošip', 'vintage' => '1999', 'status' => 'AGING', 'initial_volume' => '10.000', 'current_volume' => '10.000']);
        $this->forgetTenant();

        // Case-insensitive, and found though it sits on a later page of the unfiltered list.
        $this->visit('/cellar/lots?search=needle')->assertInertia(fn (AssertableInertia $page) => $page->has('lots.data', 1)->where('lots.data.0.lot_number', 'ZZ-1'));
    }

    public function test_the_cellar_book_pages_its_table_but_the_csv_is_the_whole_register(): void
    {
        $this->actingAsTenant($this->tenant);
        $lot = $this->lot(1);
        for ($i = 0; $i < 60; $i++) {
            CellarProcess::create(['wine_lot_id' => $lot->getKey(), 'date' => '2026-10-10', 'kind' => "Process {$i}", 'created_by_id' => $this->cellar->getKey()]);
        }
        $this->forgetTenant();

        $this->visit('/cellar/book?from=2026-10-01&to=2026-10-31&per_page=25&page=3')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows.meta.total', fn ($total): bool => $total >= 60)
            ->where('rows.meta.current_page', 3)
            // 60 processes + the lot's intake = 61 events: the third page of 25 holds the last 11.
            ->where('rows.data', fn ($data): bool => count($data) === 11)
            ->where('rows.meta.per_page', 25));

        $csv = $this->visit('/cellar/book.csv?from=2026-10-01&to=2026-10-31')->streamedContent();
        $this->assertSame(60, substr_count($csv, 'Process '), 'every one of the 60 events is in the download');
    }

    public function test_the_home_shows_the_latest_bottled_lots_and_counts_the_rest(): void
    {
        $this->actingAsTenant($this->tenant);
        for ($i = 1; $i <= 20; $i++) {
            $this->lot($i, 'BOTTLED', '0.000');
        }
        $this->forgetTenant();

        $this->visit('/cellar')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('bottled', 12)
            ->where('bottledTotal', 20));
    }
}
