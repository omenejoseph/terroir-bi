<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\EnologicalProduct;
use App\Models\EnologicalStockMovement;
use App\Models\FermentationTemplate;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WineLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Enological stock ledger, import, bulk edit, protocol needs and draft orders (parity slice 1f). */
class CellarEnologicalStockTest extends TestCase
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
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function api(string $method, string $uri, array $body = []): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->json($method, '/api/v1/'.$uri, $body, $this->tenantHeader($this->tenant));
    }

    public function test_every_stock_change_is_a_movement_with_its_cause(): void
    {
        $productId = $this->api('POST', 'enological-products', ['name' => 'Bentonite', 'category' => 'FINING', 'unit' => 'g', 'current_stock' => 500])
            ->assertCreated()->json('data.id');

        $lot = $this->inTenant(fn () => WineLot::create(['lot_number' => 'LOT-2026-001', 'name' => 'Plavac', 'grape_variety' => 'Plavac', 'vintage' => '2026', 'initial_volume' => '100.000', 'current_volume' => '100.000']));

        $this->api('POST', "wine-lots/{$lot->getKey()}/additions", ['name' => 'Bentonite', 'quantity' => 120, 'unit' => 'g', 'enological_product_id' => $productId])->assertCreated();
        $additionId = $this->inTenant(fn () => $lot->additions()->value('id'));
        $this->api('PATCH', "wine-lots/{$lot->getKey()}/additions/{$additionId}", ['quantity' => 100])->assertOk();
        $this->api('DELETE', "wine-lots/{$lot->getKey()}/additions/{$additionId}")->assertOk();
        $this->api('POST', "enological-products/{$productId}/adjust-stock", ['delta' => -50, 'note' => 'Spilt'])->assertOk()
            ->assertJsonPath('data.current_stock', '450.000');
        $this->api('POST', "enological-products/{$productId}/adjust-stock", ['delta' => -1000])->assertUnprocessable()->assertJsonValidationErrors(['delta']);

        $movements = $this->api('GET', "enological-products/{$productId}/movements")->assertOk()->json('data');
        $this->assertSame(
            ['manual', 'addition_reversed', 'addition_corrected', 'addition_corrected', 'addition', 'opening'],
            array_column($movements, 'source'),
        );
        $this->assertSame(['-50.000', '100.000', '-100.000', '120.000', '-120.000', '500.000'], array_column($movements, 'delta'));
        $this->assertSame('450.000', $movements[0]['balance_after']);
        $this->assertSame('Spilt', $movements[0]['note']);

        $this->inTenant(fn () => $this->assertSame(6, AuditLog::query()->where('action', 'enological_product.stock_adjusted')->count()));
    }

    public function test_unit_prices_keep_fractions_of_a_cent_and_additions_from_stock_are_costed(): void
    {
        // Potassium metabisulphite at 0.006 €/g: whole-cent storage made it 0.01 €/g (+67 %).
        $productId = $this->api('POST', 'enological-products', ['name' => 'K-metabisulfit', 'category' => 'SO2', 'unit' => 'g', 'current_stock' => 1000, 'cost_per_unit' => 0.6])
            ->assertCreated()
            ->assertJsonPath('data.cost_per_unit.minor', 0.6)
            ->assertJsonPath('data.cost_per_unit.formatted', '0.006')
            ->json('data.id');

        $lot = $this->inTenant(fn () => WineLot::create(['lot_number' => 'LOT-2026-001', 'name' => 'Pošip', 'grape_variety' => 'Pošip', 'vintage' => '2026', 'initial_volume' => '1000.000', 'current_volume' => '1000.000']));

        // No price sent: the addition is costed at the product's price — 150 g × 0.6 c = 90 c (whole cents gave 150 c).
        $this->api('POST', "wine-lots/{$lot->getKey()}/additions", ['name' => 'K-metabisulfit', 'quantity' => 150, 'unit' => 'g', 'enological_product_id' => $productId])->assertCreated();
        $addition = $this->inTenant(fn () => $lot->additions()->sole());
        $this->assertSame('0.6000', $addition->cost_per_unit?->minor);
        $this->assertSame(90, $addition->total_cost?->getMinorAmount());

        // Correcting the amount re-totals at the same sub-cent price.
        $this->api('PATCH', "wine-lots/{$lot->getKey()}/additions/{$addition->getKey()}", ['quantity' => 25])->assertOk();
        $this->inTenant(fn () => $this->assertSame(15, $addition->fresh()?->total_cost?->getMinorAmount()));
    }

    public function test_import_parses_tsv_upserts_by_name_and_books_stock_changes(): void
    {
        $this->inTenant(fn () => EnologicalProduct::create(['name' => 'Bentonite', 'category' => 'FINING', 'unit' => 'g', 'current_stock' => '100.000']));

        $text = "Naziv\tKategorija\tJedinica\tZaliha\tCijena\nBentonite\tFINING\tg\t250\t0,02\nYeast RC212\tYEAST\tg\t500\t0.15\n";

        $dry = $this->api('POST', 'enological-products/import', ['text' => $text, 'dry_run' => true])->assertOk()->json('data');
        $this->assertCount(2, $dry['rows']);
        $this->assertEquals(2, $dry['rows'][0]['cost_per_unit']);
        $this->inTenant(fn () => $this->assertSame(1, EnologicalProduct::query()->count(), 'dry run saves nothing'));

        $this->api('POST', 'enological-products/import', ['text' => $text])->assertOk()
            ->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);

        $this->inTenant(function (): void {
            $this->assertSame('250.000', (string) EnologicalProduct::query()->where('name', 'Bentonite')->value('current_stock'));
            $this->assertSame('500.000', (string) EnologicalProduct::query()->where('name', 'Yeast RC212')->value('current_stock'));
            $this->assertSame(2, EnologicalStockMovement::query()->where('source', 'import')->count());
        });

        $this->api('POST', 'enological-products/import', ['text' => "name\tstock\nX\tlots"])
            ->assertUnprocessable()->assertJsonPath('data.errors.0', 'Row 2: current_stock "lots" is not a number.');
    }

    public function test_bulk_edit_applies_fields_but_never_stock(): void
    {
        [$a, $b, $supplier] = $this->inTenant(fn () => [
            EnologicalProduct::create(['name' => 'A', 'category' => 'OTHER', 'unit' => 'g', 'current_stock' => '5.000']),
            EnologicalProduct::create(['name' => 'B', 'category' => 'OTHER', 'unit' => 'g', 'current_stock' => '5.000']),
            Supplier::create(['company_name' => 'Enolab']),
        ]);

        $this->api('PATCH', 'enological-products/bulk', ['ids' => [$a->getKey(), $b->getKey()], 'fields' => ['category' => 'FINING', 'supplier_id' => $supplier->getKey(), 'current_stock' => 999]])
            ->assertOk()->assertJsonPath('data.updated', 2);

        $this->inTenant(function () use ($a, $supplier): void {
            $a->refresh();
            $this->assertSame('FINING', $a->category);
            $this->assertSame($supplier->getKey(), $a->supplier_id);
            $this->assertSame('5.000', (string) $a->current_stock);
        });
    }

    public function test_protocol_needs_and_draft_orders_per_supplier(): void
    {
        [$yeast, $supplier] = $this->inTenant(function () {
            $supplier = Supplier::create(['company_name' => 'Enolab']);
            $yeast = EnologicalProduct::create(['name' => 'Yeast RC212', 'category' => 'YEAST', 'unit' => 'g', 'current_stock' => '100.000', 'cost_per_unit' => 15, 'supplier_id' => $supplier->getKey()]);
            $template = FermentationTemplate::create(['name' => 'Red']);
            $template->additives()->create(['name' => 'Yeast', 'enological_product_id' => $yeast->getKey(), 'dose' => '20', 'basis' => 'per_hL']);
            $template->additives()->create(['name' => 'Tannin', 'dose' => '0.5', 'basis' => 'per_kg']);
            foreach (['LOT-2026-001' => '1000.000', 'LOT-2026-002' => '500.000'] as $number => $volume) {
                WineLot::create(['lot_number' => $number, 'name' => $number, 'grape_variety' => 'Plavac', 'vintage' => '2026', 'status' => 'FERMENTING',
                    'initial_volume' => $volume, 'current_volume' => $volume, 'fermentation_template_id' => $template->getKey(), 'harvest_weight_kg' => '1000.000']);
            }

            return [$yeast, $supplier];
        });

        $needs = $this->api('GET', 'enological-products/needs')->assertOk()->json('data');
        $byName = array_column($needs, null, 'product_name');
        $this->assertEquals(300, $byName['Yeast RC212']['total_needed']);   // 20 g/hL × (10 + 5) hL
        $this->assertEquals(200, $byName['Yeast RC212']['deficit']);
        $this->assertSame('Enolab', $byName['Yeast RC212']['supplier']['name']);
        $this->assertEquals(1000, $byName['Tannin']['total_needed']);       // 0.5 × 1000 kg × 2 lots
        $this->assertNull($byName['Tannin']['enological_product_id']);

        $orders = $this->api('POST', 'enological-products/draft-orders')->assertCreated()->json('data');
        $this->assertCount(1, $orders);
        $this->assertSame('Enolab', $orders[0]['supplier']);

        $this->inTenant(function () use ($supplier): void {
            $order = SupplierOrder::query()->with('items')->sole();
            $this->assertSame($supplier->getKey(), $order->supplier_id);
            $this->assertSame('Yeast RC212', $order->items->first()?->description);
            $this->assertSame(3000, $order->total_amount->getMinorAmount());   // 200 g × 15
        });
        unset($yeast);
    }
}
