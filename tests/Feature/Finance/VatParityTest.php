<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TenantRole;
use App\Models\Cost;
use App\Models\Inflow;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * VAT as the old app handled it: the total is GROSS (VAT included), VAT is the portion inside it,
 * net is total minus VAT and is only shown once VAT is known; the VAT summaries count only
 * "invoiced" entries (costs linked to an e-invoice, money-in of type invoice), never credit notes.
 */
class VatParityTest extends TestCase
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
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    /** @param array<string, mixed> $o */
    private function cost(array $o): void
    {
        $this->actingAsTenant($this->tenant);
        Cost::create($o + ['date' => '2026-06-10', 'status' => 'PENDING', 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();
    }

    /** @param array<string, mixed> $o */
    private function inflow(array $o): void
    {
        $this->actingAsTenant($this->tenant);
        Inflow::create($o + ['date' => '2026-06-10', 'status' => 'RECEIVED', 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();
    }

    public function test_a_cost_total_is_gross_and_net_is_total_minus_vat(): void
    {
        // The old app's own example: €256.25 including €51.25 VAT (25% of the €205.00 net).
        $this->postJson('/api/v1/costs', ['total_amount' => 25625, 'vat_amount' => 5125, 'category' => 'Supplies'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.total_amount.minor', 25625)
            ->assertJsonPath('data.vat_amount.minor', 5125)
            ->assertJsonPath('data.net_amount.minor', 20500);
    }

    public function test_an_inflow_amount_is_gross_and_net_is_amount_minus_vat(): void
    {
        $this->postJson('/api/v1/inflows', ['amount' => 48600, 'vat_amount' => 9720], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.amount.minor', 48600)
            ->assertJsonPath('data.vat_amount.minor', 9720)
            ->assertJsonPath('data.net_amount.minor', 38880);
    }

    public function test_net_is_not_shown_until_vat_is_known(): void
    {
        $this->postJson('/api/v1/costs', ['total_amount' => 1000, 'category' => 'Supplies'], $this->headers())
            ->assertCreated()->assertJsonPath('data.vat_amount', null)->assertJsonPath('data.net_amount', null);
        $this->postJson('/api/v1/inflows', ['amount' => 1000], $this->headers())
            ->assertCreated()->assertJsonPath('data.vat_amount', null)->assertJsonPath('data.net_amount', null);
    }

    public function test_cost_vat_summary_counts_invoiced_costs_whatever_their_category(): void
    {
        $this->cost(['total_amount' => 25000, 'vat_amount' => 5000, 'category' => 'Kitchen Raw Materials', 'is_invoice' => true]); // e-invoice linked
        $this->cost(['total_amount' => 12500, 'vat_amount' => 2500, 'category' => 'Invoice']);                                     // reserved category still counts
        $this->cost(['total_amount' => 9000, 'vat_amount' => 1800, 'category' => 'Salary']);                                       // a plain payment: not invoiced

        $this->getJson('/api/v1/costs/analytics?from=2026-06-01&to=2026-06-30', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.invoiced.count', 2)
            ->assertJsonPath('data.invoiced.total.minor', 37500)
            ->assertJsonPath('data.invoiced.vat.minor', 7500);
    }

    public function test_the_invoices_tab_includes_flagged_costs_and_others_excludes_them(): void
    {
        $this->cost(['total_amount' => 1000, 'category' => 'Supplies', 'is_invoice' => true, 'reference' => 'FLAGGED']);
        $this->cost(['total_amount' => 1000, 'category' => 'Invoice', 'reference' => 'CATEGORY']);
        $this->cost(['total_amount' => 1000, 'category' => 'Supplies', 'reference' => 'PLAIN']);

        $refs = function (string $group): array {
            $rows = $this->getJson("/api/v1/costs?group={$group}", $this->headers())->assertOk()->json('data');
            $out = array_column($rows, 'reference');
            sort($out);

            return $out;
        };

        $this->assertSame(['CATEGORY', 'FLAGGED'], $refs('invoices'));
        $this->assertSame(['PLAIN'], $refs('others'));
    }

    public function test_inflow_vat_total_ignores_credit_notes_and_entries_without_vat(): void
    {
        $this->inflow(['amount' => 12500, 'vat_amount' => 2500, 'is_invoice' => true]);
        $this->inflow(['amount' => 24000, 'vat_amount' => 4000]);
        $this->inflow(['amount' => 5000]);                                                   // no VAT recorded
        $this->inflow(['amount' => 3000, 'vat_amount' => 600, 'is_credit_note' => true]);    // credit notes are left out

        $this->getJson('/api/v1/inflows/analytics?from=2026-06-01&to=2026-06-30', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.vat.minor', 6500);
    }

    public function test_inflow_invoiced_cards_follow_the_invoice_flag(): void
    {
        $this->inflow(['amount' => 10000, 'category' => 'Wine Sales', 'is_invoice' => true]);
        $this->inflow(['amount' => 4000, 'category' => 'Customer payments']);   // a bank receipt, not an invoice
        $this->inflow(['amount' => 6000, 'category' => 'Invoice']);

        $this->getJson('/api/v1/inflows/analytics?from=2026-06-01&to=2026-06-30', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.invoiced.count', 2)
            ->assertJsonPath('data.invoiced.total.minor', 16000);
    }

    public function test_vat_can_be_corrected_later_and_is_never_negative(): void
    {
        $id = $this->postJson('/api/v1/costs', ['total_amount' => 12500, 'category' => 'Supplies'], $this->headers())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/costs/{$id}", ['vat_amount' => 2500, 'is_invoice' => true], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.net_amount.minor', 10000)
            ->assertJsonPath('data.is_invoice', true);
        $this->patchJson("/api/v1/costs/{$id}", ['vat_amount' => -1], $this->headers())->assertUnprocessable();
    }
}
