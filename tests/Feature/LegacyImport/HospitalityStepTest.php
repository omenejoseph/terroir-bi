<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Concerns\LegacyFixture;
use Tests\TestCase;

/**
 * Programs, agency prices, reservations and their history come across with their money verbatim —
 * never re-priced — and orphans are reported rather than dropped silently (docs/21 §2.6).
 */
class HospitalityStepTest extends TestCase
{
    use InteractsWithTenancy;
    use LegacyFixture;
    use RefreshDatabase;

    private const STEPS = ['users', 'pricing_tiers', 'customers', 'hospitality'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLegacySchema();

        $this->legacyInsert('User', ['id' => 'u1', 'name' => 'Ana Bibic', 'email' => 'ana@bibich.co', 'hashedPassword' => '$2b$12$x', 'role' => 'HOSPITALITY', 'createdAt' => '2026-01-01', 'canEditOrders' => 'f', 'canSeeShippedOrders' => 'f']);
        $this->legacyInsert('Customer', ['id' => 'a1', 'companyName' => 'Adriatic Tours', 'email' => 'a1@x.hr', 'isActive' => 't', 'rebatePercent' => 0, 'excludeFromStats' => 'f', 'hidePrices' => 'f', 'isAgency' => 't', 'allowSingleBottle' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);

        $this->legacyInsert('HospitalityProgram', ['id' => 'p1', 'name' => 'Classic tasting', 'basePrice' => '50', 'durationMins' => 90, 'maxPax' => 30, 'isActive' => 't', 'sortOrder' => 1, 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->legacyInsert('HospitalityAgencyPrice', ['id' => 'ap1', 'programId' => 'p1', 'customerId' => 'a1', 'pricePerPax' => '42.5', 'rebatePercent' => '15', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        // An agency deleted in the old app: its price cannot come across.
        $this->legacyInsert('HospitalityAgencyPrice', ['id' => 'ap2', 'programId' => 'p1', 'customerId' => 'gone', 'pricePerPax' => '40', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);

        // Stored figures are what the group was charged — 41.99 is deliberately not the price book's 42.50.
        $this->legacyInsert('Reservation', ['id' => 'r1', 'date' => '2026-07-20 11:00:00', 'pax' => 12, 'status' => 'CONFIRMED', 'pricePerPax' => '41.99', 'totalAmount' => '503.88', 'guestName' => 'Bus 1', 'programId' => 'p1', 'customerId' => 'a1', 'createdById' => 'u1', 'createdAt' => '2026-06-01', 'updatedAt' => '2026-06-02']);
        $this->legacyInsert('Reservation', ['id' => 'r2', 'date' => '2026-07-21 11:00:00', 'pax' => 2, 'status' => 'PENDING', 'pricePerPax' => '50', 'totalAmount' => '100', 'guestName' => 'Lost agency', 'programId' => 'p1', 'customerId' => 'gone', 'createdById' => 'u1', 'createdAt' => '2026-06-01', 'updatedAt' => '2026-06-01']);
        $this->legacyInsert('ReservationStatusHistory', ['id' => 'h1', 'reservationId' => 'r1', 'status' => 'PENDING', 'createdById' => 'u1', 'createdAt' => '2026-06-01']);
        $this->legacyInsert('ReservationStatusHistory', ['id' => 'h2', 'reservationId' => 'r1', 'status' => 'CONFIRMED', 'note' => 'Deposit in', 'createdById' => 'u1', 'createdAt' => '2026-06-02']);
    }

    private function import(): ImportContext
    {
        $tenant = $this->createTenant();
        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx, self::STEPS);

        return $ctx;
    }

    public function test_reservations_keep_their_charged_figures_and_history(): void
    {
        $ctx = $this->import();

        $program = DB::table('hospitality_programs')->first();
        $this->assertNotNull($program);
        $this->assertSame(5000, (int) $program->base_price);
        $this->assertSame(90, (int) $program->duration_mins);

        $price = DB::table('hospitality_agency_prices')->first();
        $this->assertNotNull($price);
        $this->assertSame(4250, (int) $price->price_per_pax);
        $this->assertSame(15.0, (float) $price->rebate_percent);

        $bus = DB::table('reservations')->where('guest_name', 'Bus 1')->first();
        $this->assertNotNull($bus);
        $this->assertSame(4199, (int) $bus->price_per_pax);
        $this->assertSame(50388, (int) $bus->total_amount);
        $this->assertSame('CONFIRMED', $bus->status);
        $this->assertNotNull($bus->customer_id);
        $this->assertSame(
            [['PENDING', null], ['CONFIRMED', 'Deposit in']],
            DB::table('reservation_status_histories')->orderBy('created_at')->get(['status', 'note'])->map(fn ($h) => [$h->status, $h->note])->all(),
        );

        $counts = $ctx->report->counts();
        $this->assertSame(1, $counts['hospitality_agency_prices']['skipped']);
        $this->assertSame(2, $counts['reservations']['written']);
    }

    public function test_an_orphaned_reservation_stays_as_a_walk_in_and_is_reported(): void
    {
        $ctx = $this->import();

        $lost = DB::table('reservations')->where('guest_name', 'Lost agency')->first();
        $this->assertNotNull($lost);
        $this->assertNull($lost->customer_id);
        $this->assertSame(10000, (int) $lost->total_amount);
        $this->assertNotEmpty(array_filter($ctx->report->warnings(), fn (string $w): bool => str_contains($w, 'kept as a walk-in')));
    }

    public function test_running_it_again_updates_in_place(): void
    {
        $tenant = $this->createTenant();
        $ids = new IdMap($tenant->getKey());
        app(LegacyImporter::class)->run(new ImportContext($tenant, DB::connection('legacy'), $ids, new Report), self::STEPS);
        app(LegacyImporter::class)->run(new ImportContext($tenant, DB::connection('legacy'), $ids, new Report), self::STEPS);

        $this->assertSame(2, DB::table('reservations')->count());
        $this->assertSame(2, DB::table('reservation_status_histories')->count());
        $this->assertSame(1, DB::table('hospitality_agency_prices')->count());
    }
}
