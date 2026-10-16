<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\CellarTastingNote;
use App\Models\EnologicalProduct;
use App\Models\EnologicalStockMovement;
use App\Models\FermentationTemplate;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\TastingReport;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WineLot;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Cellar › Tastings, Products and Protocols as Inertia pages (parity areas K, F10–F18, M). */
class WebCellarTastingsProductsProtocolsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function inTenant(callable $fn): mixed
    {
        $this->actingAsTenant($this->tenant);
        try {
            return $fn();
        } finally {
            $this->forgetTenant();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function web(string $method, string $uri, array $data = [], ?User $as = null, bool $json = false): TestResponse
    {
        $req = $this->actingAs($as ?? $this->cellar)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])->from('/cellar');

        return $json ? $req->json($method, $uri, $data) : $req->call($method, $uri, $data);
    }

    private function lot(string $number, string $name): WineLot
    {
        return WineLot::create(['lot_number' => $number, 'name' => $name, 'grape_variety' => $name, 'vintage' => '2026', 'status' => 'FERMENTING', 'initial_volume' => '1000.000', 'current_volume' => '1000.000', 'harvest_weight_kg' => '1000.000']);
    }

    public function test_tasting_report_lifecycle_through_the_pages(): void
    {
        [$a, $b] = $this->inTenant(fn () => [$this->lot('LOT-2026-001', 'Plavac'), $this->lot('LOT-2026-002', 'Pošip')]);

        $response = $this->web('POST', '/cellar/tastings', ['title' => 'October panel', 'date' => '2026-10-10', 'wines' => [
            ['wine_lot_id' => $a->getKey(), 'score' => 90, 'nose' => 'Cherry'],
            ['wine_lot_id' => $b->getKey(), 'score' => 80],
        ]]);
        $report = $this->inTenant(fn () => TastingReport::query()->sole());
        $response->assertRedirect('/cellar/tastings/'.$report->getKey());

        $this->web('GET', '/cellar/tastings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Tastings/Index')->where('reports.0.average_score', 85)->has('lots', 2));
        $this->web('GET', '/cellar/tastings/'.$report->getKey())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Tastings/Show')->has('report.wines', 2));

        $this->web('PATCH', '/cellar/tastings/'.$report->getKey(), ['wines' => [['wine_lot_id' => $a->getKey(), 'score' => 92]]])->assertSessionHas('success');
        $this->inTenant(fn () => $this->assertSame(1, CellarTastingNote::query()->count()));

        $this->web('DELETE', '/cellar/tastings/'.$report->getKey())->assertRedirect('/cellar/tastings');
        $this->inTenant(fn () => $this->assertSame(0, CellarTastingNote::query()->count()));
    }

    public function test_products_page_stock_import_bulk_and_draft_orders(): void
    {
        [$supplier, $template] = $this->inTenant(function () {
            $supplier = Supplier::create(['company_name' => 'Enolab']);
            $template = FermentationTemplate::create(['name' => 'Red']);

            return [$supplier, $template];
        });

        $this->web('POST', '/cellar/products', ['name' => 'Yeast RC212', 'category' => 'YEAST', 'unit' => 'g', 'current_stock' => 100, 'supplier_id' => $supplier->getKey(), 'so2_uplift_per_unit' => null])->assertSessionHas('success');
        $yeast = $this->inTenant(fn () => EnologicalProduct::query()->where('name', 'Yeast RC212')->sole());
        $this->inTenant(function () use ($template, $yeast): void {
            $template->additives()->create(['name' => 'Yeast', 'enological_product_id' => $yeast->getKey(), 'dose' => '20', 'basis' => 'per_hL']);
            WineLot::create(['lot_number' => 'LOT-2026-001', 'name' => 'Plavac', 'grape_variety' => 'Plavac', 'vintage' => '2026', 'status' => 'FERMENTING', 'initial_volume' => '1000.000', 'current_volume' => '1000.000', 'fermentation_template_id' => $template->getKey()]);
        });

        $this->web('GET', '/cellar/products')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Products')
            ->has('products', 1)
            ->where('needs.0.deficit', 100)          // 20 g/hL × 10 hL − 100 g
            ->where('suppliers.0.label', 'Enolab'));

        $this->web('POST', '/cellar/products/'.$yeast->getKey().'/adjust-stock', ['delta' => -10, 'note' => 'Count'])->assertSessionHas('success');
        $this->web('GET', '/cellar/products/'.$yeast->getKey().'/movements', json: true)->assertOk()->assertJsonPath('data.0.source', 'manual')->assertJsonPath('data.1.source', 'opening');

        $this->web('POST', '/cellar/products/import', ['text' => "name\tcategory\tunit\tstock\nBentonite\tFINING\tg\t500", 'dry_run' => true], json: true)
            ->assertOk()->assertJsonPath('data.rows.0.name', 'Bentonite');
        $this->web('POST', '/cellar/products/import', ['text' => "name\tcategory\tunit\tstock\nBentonite\tFINING\tg\t500"])->assertSessionHas('success');
        $this->web('POST', '/cellar/products/import', ['text' => "name\tstock\nX\tlots"])->assertSessionHasErrors('text');

        $bentonite = $this->inTenant(fn () => EnologicalProduct::query()->where('name', 'Bentonite')->sole());
        $this->web('PATCH', '/cellar/products/bulk', ['ids' => [$bentonite->getKey(), $yeast->getKey()], 'fields' => ['min_stock' => 50]])->assertSessionHas('success');
        $this->web('POST', '/cellar/products/draft-orders')->assertSessionHas('success');

        $this->inTenant(function () use ($bentonite): void {
            $this->assertSame('50.000', (string) $bentonite->refresh()->min_stock);
            $this->assertSame(3, EnologicalStockMovement::query()->count());
            $this->assertSame(1, SupplierOrder::query()->count());
        });

        $this->web('DELETE', '/cellar/products/'.$bentonite->getKey())->assertForbidden();
        $this->web('DELETE', '/cellar/products/'.$bentonite->getKey(), as: $this->admin)->assertSessionHas('success');
    }

    public function test_protocols_page_and_editor(): void
    {
        $this->web('POST', '/cellar/protocols', [
            'name' => 'Red classic', 'wine_type' => 'RED', 'target_temp_min' => 24, 'target_temp_max' => 28, 'mlf' => true,
            'stages' => [['name' => 'Fermentation', 'day_start' => 0, 'day_end' => 10, 'actions' => [['type' => 'TASK', 'description' => 'Pump-over']]]],
            'additives' => [['name' => 'Yeast', 'dose' => 20, 'basis' => 'per_hL']],
        ])->assertSessionHas('success');
        $template = $this->inTenant(fn () => FermentationTemplate::query()->sole());

        $this->web('GET', '/cellar/protocols')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Protocols')
            ->where('templates.0.name', 'Red classic')
            ->where('templates.0.stages.0.actions.0.description', 'Pump-over')
            ->where('templates.0.additives.0.basis', 'per_hL')
            ->where('templates.0.lots_in_use', 0)
            ->has('alerts'));

        $this->web('PATCH', '/cellar/protocols/'.$template->getKey(), ['name' => 'Red classic v2', 'additives' => []])->assertSessionHas('success');
        $this->inTenant(fn () => $this->assertSame(0, $template->refresh()->additives()->count()));
        $this->web('DELETE', '/cellar/protocols/'.$template->getKey(), as: $this->admin)->assertSessionHas('success');
    }
}
