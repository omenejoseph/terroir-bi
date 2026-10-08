<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TenantRole;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** The two gaps the legacy import exposed: VAT on money-received entries, and descriptions longer than 255 characters. */
class InflowVatAndLongTextTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($this->tenant, [TenantRole::Admin]));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_an_inflow_can_record_its_vat(): void
    {
        $this->postJson('/api/v1/inflows', ['amount' => 48600, 'vat_amount' => 9600], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.amount.minor', 48600)
            ->assertJsonPath('data.vat_amount.minor', 9600);
    }

    public function test_vat_is_optional_and_unknown_is_not_zero(): void
    {
        $this->postJson('/api/v1/inflows', ['amount' => 1000], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.vat_amount', null);

        $this->postJson('/api/v1/inflows', ['amount' => 1000, 'vat_amount' => 0], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.vat_amount.minor', 0);
    }

    public function test_vat_can_be_added_later_and_must_not_be_negative(): void
    {
        $id = $this->postJson('/api/v1/inflows', ['amount' => 12500], $this->headers())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/inflows/{$id}", ['vat_amount' => 2500], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.vat_amount.minor', 2500);

        $this->patchJson("/api/v1/inflows/{$id}", ['vat_amount' => -1], $this->headers())->assertUnprocessable();
    }

    public function test_cost_and_cost_line_descriptions_can_be_longer_than_255_characters(): void
    {
        $long = rtrim(str_repeat('Tovarni list 8525673085, datum slanja. ', 11)); // ~420 characters (the framework trims trailing spaces)

        $this->postJson('/api/v1/costs', [
            'total_amount' => 5000, 'category' => 'Transport', 'description' => $long,
            'items' => [['description' => $long, 'unit_price' => 5000, 'quantity' => 1]],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.description', $long)
            ->assertJsonPath('data.items.0.description', $long);
    }

    public function test_a_cost_line_description_still_has_a_sensible_ceiling(): void
    {
        $this->postJson('/api/v1/costs', [
            'total_amount' => 5000, 'category' => 'Transport',
            'items' => [['description' => str_repeat('x', 2001), 'unit_price' => 5000, 'quantity' => 1]],
        ], $this->headers())->assertUnprocessable();
    }
}
