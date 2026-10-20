<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\ProduceItemAction;
use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WineLot;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Orders\CogsSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** The Recipe tab: cost per line and in total, cellar wine costed per litre, units that convert, custom lines. */
class RecipeCostTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private User $admin;

    private string $tenantId;

    private InventoryItem $wine;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->createTenant();
        $this->tenantId = (string) $tenant->getKey();
        $this->admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $this->wine = InventoryItem::create(['name' => 'House Red', 'sku' => 'HR', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '0.000']);
        $this->forgetTenant();
    }

    private function lot(string $grapeCostMinor = '400000', string $volume = '1000.000'): WineLot
    {
        $this->actingAsTenant($this->tenantModel());
        $lot = WineLot::create([
            'lot_number' => 'L1', 'name' => 'Plavac 2024', 'grape_variety' => 'Plavac', 'vintage' => '2024', 'wine_type' => 'RED',
            'initial_volume' => $volume, 'current_volume' => $volume, 'status' => 'READY', 'grape_cost' => (int) $grapeCostMinor,
        ]);
        $this->forgetTenant();

        return $lot;
    }

    private function tenantModel(): Tenant
    {
        return Tenant::query()->findOrFail($this->tenantId);
    }

    private function input(string $sku, string $unit, ?int $cost = null, string $stock = '0.000'): InventoryItem
    {
        $this->actingAsTenant($this->tenantModel());
        $item = InventoryItem::create(['name' => $sku, 'sku' => $sku, 'category' => 'RAW_MATERIAL', 'unit' => $unit, 'current_stock' => $stock, 'cost_per_unit' => $cost]);
        $this->forgetTenant();

        return $item;
    }

    /** @param  array<string, mixed>  $body */
    private function save(array $body)
    {
        return $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenantId])
            ->put('/inventory/'.$this->wine->getKey().'/recipe', $body);
    }

    private function show(): AssertableInertia
    {
        $response = $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenantId])
            ->get('/inventory/'.$this->wine->getKey());

        return AssertableInertia::fromTestResponse($response);
    }

    public function test_cellar_wine_is_costed_per_litre_on_the_quantity_entered_in_ml(): void
    {
        $lot = $this->lot('400000', '1000.000'); // 4.00 per litre
        $cork = $this->input('CORK', 'units', 30);

        $this->save(['items' => [
            ['wine_lot_id' => $lot->getKey(), 'quantity' => '750', 'unit' => 'ml'],
            ['input_id' => $cork->getKey(), 'quantity' => '1'],
            ['custom_name' => 'Label', 'custom_unit' => 'pc', 'custom_cost' => 25, 'quantity' => '1'],
        ]])->assertSessionHasNoErrors();

        // 0.75 l × 4.00 = 3.00, plus the cork 0.30 and the label 0.25.
        $this->show()->where('recipe.0.kind', 'cellar')
            ->where('recipe.0.line_cost.minor', 300)
            ->where('recipe.0.unit', 'ml')
            ->where('recipe.1.kind', 'catalog')
            ->where('recipe.1.line_cost.minor', 30)
            ->where('recipe.2.kind', 'custom')
            ->where('recipe.2.line_cost.minor', 25)
            ->where('recipeCost.per_unit.minor', 355)
            ->where('recipeCost.complete', true);

        // The item's own cost follows its recipe.
        $this->actingAsTenant($this->tenantModel());
        $this->assertSame(355, $this->wine->refresh()->cost_per_unit->getMinorAmount());
        $this->assertSame(355, app(CogsSnapshot::class)->perBottle($this->wine)->getMinorAmount());
    }

    public function test_a_catalog_liquid_converts_and_is_consumed_in_its_own_unit(): void
    {
        $brandy = $this->input('BRANDY', 'liters', 1000, '10.000'); // 10.00 per litre

        $this->save(['items' => [['input_id' => $brandy->getKey(), 'quantity' => '50', 'unit' => 'ml']]])
            ->assertSessionHasNoErrors();

        // 50 ml of a 10.00/l spirit is 0.50.
        $this->show()->where('recipe.0.line_cost.minor', 50)->where('recipeCost.per_unit.minor', 50);

        $this->actingAsTenant($this->tenantModel());
        app(ProduceItemAction::class)->execute($this->wine, '10');

        // 10 bottles × 50 ml = 0.5 l taken from the 10 l on hand.
        $this->assertSame('9.500', (string) $brandy->refresh()->current_stock);
    }

    public function test_cost_only_lines_are_not_taken_from_stock(): void
    {
        $lot = $this->lot();
        $this->save(['items' => [['wine_lot_id' => $lot->getKey(), 'quantity' => '750', 'unit' => 'ml']]]);

        $this->actingAsTenant($this->tenantModel());
        app(ProduceItemAction::class)->execute($this->wine, '4');

        $this->assertSame('1000.000', (string) $lot->refresh()->current_volume);
        $this->assertSame('4.000', (string) $this->wine->refresh()->current_stock);
    }

    public function test_an_unpriced_line_leaves_the_total_incomplete_and_the_item_cost_alone(): void
    {
        $cork = $this->input('CORK', 'units', 30);
        $label = $this->input('LABEL', 'units');

        $this->save(['items' => [
            ['input_id' => $cork->getKey(), 'quantity' => '1'],
            ['input_id' => $label->getKey(), 'quantity' => '1'],
        ]]);

        $this->show()->where('recipeCost.per_unit.minor', 30)->where('recipeCost.complete', false);

        $this->actingAsTenant($this->tenantModel());
        $this->assertNull($this->wine->refresh()->cost_per_unit);
    }

    public function test_an_item_stocked_in_cases_costs_a_cases_worth_of_bottles(): void
    {
        $this->actingAsTenant($this->tenantModel());
        $this->wine->update(['unit' => 'cases', 'bottles_per_case' => 6]);
        $this->forgetTenant();
        $cork = $this->input('CORK', 'units', 30);

        $this->save(['items' => [['input_id' => $cork->getKey(), 'quantity' => '1']]]);

        $this->show()->where('recipeCost.per_unit.minor', 180)->where('recipeCost.multiplier', 6);
    }

    public function test_units_that_do_not_convert_and_ambiguous_lines_are_refused(): void
    {
        $lot = $this->lot();
        $cork = $this->input('CORK', 'units', 30);

        $this->save(['items' => [['input_id' => $cork->getKey(), 'quantity' => '5', 'unit' => 'ml']]])
            ->assertSessionHasErrors('items.0.unit');
        $this->save(['items' => [['input_id' => $cork->getKey(), 'wine_lot_id' => $lot->getKey(), 'quantity' => '1']]])
            ->assertSessionHasErrors('items.0.input_id');
        $this->save(['items' => [['quantity' => '1']]])->assertSessionHasErrors('items.0.input_id');
        $this->save(['items' => [
            ['wine_lot_id' => $lot->getKey(), 'quantity' => '1', 'unit' => 'l'],
            ['wine_lot_id' => $lot->getKey(), 'quantity' => '2', 'unit' => 'l'],
        ]])->assertSessionHasErrors('items.1.wine_lot_id');
        // Kilograms do not convert to millilitres.
        $this->save(['items' => [['wine_lot_id' => $lot->getKey(), 'quantity' => '1', 'unit' => 'kg']]])
            ->assertSessionHasErrors('items.0.unit');
    }

    public function test_the_picker_options_carry_costs_cellar_wine_and_recipes_to_copy(): void
    {
        $lot = $this->lot();
        $cork = $this->input('CORK', 'units', 30);
        $brandy = $this->input('BRANDY', 'liters', 1000);
        $this->save(['items' => [['input_id' => $cork->getKey(), 'quantity' => '1']]]);

        $this->actingAsTenant($this->tenantModel());
        $other = InventoryItem::create(['name' => 'Gift set', 'sku' => 'GS', 'category' => 'FINISHED', 'unit' => 'bottles']);
        InventoryItem::create(['name' => 'No recipe', 'sku' => 'NR', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $this->forgetTenant();

        $response = $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenantId])
            ->get('/inventory/'.$other->getKey(), $this->inertiaPartial('Inventory/Show', ['recipeInputOptions', 'recipeCellarLots', 'recipeSources']))
            ->assertOk();

        $props = $response->json('props');
        $brandyOption = collect($props['recipeInputOptions'])->firstWhere('id', $brandy->getKey());
        $this->assertEquals(1000, $brandyOption['unit_cost']['minor']);
        $this->assertSame(['ml', 'cl', 'l'], $brandyOption['unit_options']);
        $this->assertSame($lot->getKey(), $props['recipeCellarLots'][0]['id']);
        $this->assertSame(400, $props['recipeCellarLots'][0]['cost_per_liter']['minor']);
        // Only the product that has a recipe is offered to copy from.
        $this->assertCount(1, $props['recipeSources']);
        $this->assertSame($this->wine->getKey(), $props['recipeSources'][0]['id']);
        $this->assertSame($cork->getKey(), $props['recipeSources'][0]['lines'][0]['input_id']);
    }

    public function test_a_raw_material_bought_by_the_pack_is_costed_per_unit(): void
    {
        // 12 corks in a box costing 6.00: each cork is 0.50.
        $corks = $this->input('CORKS', 'units', 600);
        $this->actingAsTenant($this->tenantModel());
        $corks->update(['pack_size' => 12]);
        $this->forgetTenant();

        $this->save(['items' => [['input_id' => $corks->getKey(), 'quantity' => '2']]])->assertSessionHasNoErrors();

        $this->show()->where('recipe.0.line_cost.minor', 100)->where('recipeCost.per_unit.minor', 100);
    }

    public function test_producing_with_short_stock_is_a_form_error_naming_everything_that_is_short(): void
    {
        $label = $this->input('LABEL', 'units', 10, '0.000');
        $cork = $this->input('CORK', 'units', 30, '3.000');
        $this->save(['items' => [
            ['input_id' => $label->getKey(), 'quantity' => '1'],
            ['input_id' => $cork->getKey(), 'quantity' => '1'],
        ]]);

        $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenantId])
            ->post('/inventory/'.$this->wine->getKey().'/produce', ['display_quantity' => '10'])
            ->assertSessionHasErrors('display_quantity');

        $message = session('errors')->first('display_quantity');
        $this->assertStringContainsString('LABEL', $message);
        $this->assertStringContainsString('CORK', $message);

        // Nothing was produced or taken.
        $this->actingAsTenant($this->tenantModel());
        $this->assertSame('0.000', (string) $this->wine->refresh()->current_stock);
        $this->assertSame('3.000', (string) $cork->refresh()->current_stock);
    }

    public function test_each_production_run_is_recorded_with_what_it_took(): void
    {
        $cork = $this->input('CORK', 'units', 30, '100.000');
        $this->save(['items' => [['input_id' => $cork->getKey(), 'quantity' => '2']]]);

        $produce = fn (string $qty) => $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenantId])
            ->post('/inventory/'.$this->wine->getKey().'/produce', ['display_quantity' => $qty])->assertSessionHasNoErrors();
        $produce('10');
        $produce('5');

        $this->show()
            ->has('productionRuns', 2)
            ->where('productionRuns.0.quantity', '5.000')
            ->where('productionRuns.0.by', $this->admin->name)
            ->where('productionRuns.0.inputs.0.name', 'CORK')
            ->where('productionRuns.0.inputs.0.quantity', '10.000')
            ->where('productionRuns.1.quantity', '10.000')
            ->where('productionRuns.1.inputs.0.quantity', '20.000');
    }
}
