<?php

declare(strict_types=1);

namespace Tests\Feature\Hospitality;

use App\Actions\Hospitality\CreateReservationAction;
use App\Actions\Hospitality\SetAgencyPriceAction;
use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\HospitalityProgram;
use App\Models\Reservation;
use App\Queries\AgencyPerformanceQuery;
use App\Queries\CustomerHospitalityQuery;
use App\Queries\HospitalityAnalyticsQuery;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

/**
 * The Analytics tab, the agency performance table and the customer page's Hospitality tab — the
 * metric definitions in docs/21 §2.3: no cancelled bookings and no "exclude from stats" customers
 * in the figures, conversion over every booking, discount = base × guests − charged.
 */
class HospitalityAnalyticsTest extends HospitalityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book(HospitalityProgram $program, string $date, int $pax, ?Customer $customer = null, ReservationStatus $status = ReservationStatus::Pending): Reservation
    {
        $reservation = app(CreateReservationAction::class)->execute([
            'date' => $date,
            'pax' => $pax,
            'program_id' => $program->getKey(),
            'customer_id' => $customer?->getKey(),
            'guest_name' => 'Group',
        ], $this->user->getKey());

        if ($status !== ReservationStatus::Pending) {
            $reservation->forceFill(['status' => $status])->save();
        }

        return $reservation;
    }

    public function test_the_year_counts_what_was_booked_and_leaves_out_cancellations_and_excluded_customers(): void
    {
        $tasting = $this->program(5000);
        $tour = $this->program(8000, ['name' => 'Vineyard tour']);
        $agency = $this->customer('Adriatic Tours', agency: true);
        $house = $this->customer('House account', agency: true, attributes: ['exclude_from_stats' => true]);
        app(SetAgencyPriceAction::class)->execute($tasting, $agency, 4000);

        $this->book($tasting, '2026-03-10 11:00', 10, $agency, ReservationStatus::Completed); // 40 000
        $this->book($tour, '2026-03-20 11:00', 5, null, ReservationStatus::Paid);             // 40 000
        $this->book($tasting, '2026-08-01 11:00', 4, null, ReservationStatus::Confirmed);     // 20 000, upcoming
        $this->book($tasting, '2026-04-01 11:00', 6, null, ReservationStatus::Cancelled);     // out
        $this->book($tasting, '2026-05-01 11:00', 8, $house, ReservationStatus::Completed);   // out
        $this->book($tasting, '2025-03-05 11:00', 2, null, ReservationStatus::Completed);     // last year, 10 000

        $a = app(HospitalityAnalyticsQuery::class)->get(2026);

        $this->assertSame(3, $a['kpis']['reservations']);
        $this->assertSame(100000, $a['kpis']['revenue']['minor']);
        $this->assertSame(19, $a['kpis']['guests']);
        $this->assertSame(5263, $a['kpis']['revenue_per_guest']['minor']);
        $this->assertSame(1, $a['kpis']['upcoming']);
        $this->assertSame(20000, $a['kpis']['pipeline']['minor']);
        // Converted 3 (completed, paid, confirmed) of the 4 non-excluded bookings, the cancelled one included.
        $this->assertSame(75.0, $a['kpis']['conversion_rate']);

        $this->assertSame([2025, 2026, 2027], $a['years']);
        $this->assertSame(80000, $a['months'][2]['revenue']['minor']);
        $this->assertSame(10000, $a['months'][2]['previous_revenue']['minor']);

        $this->assertSame(['Classic tasting', 'Vineyard tour'], array_column($a['programs'], 'name'));
        $this->assertSame(60000, $a['programs'][0]['revenue']['minor']);
        $this->assertSame(60.0, $a['programs'][0]['share']);

        // Only agencies, and not the excluded one.
        $this->assertSame(['Adriatic Tours'], array_column($a['top_agencies'], 'name'));
    }

    public function test_agency_performance_shows_the_discount_and_compares_with_the_period_before(): void
    {
        $tasting = $this->program(5000);
        $agency = $this->customer('Adriatic Tours', agency: true);
        app(SetAgencyPriceAction::class)->execute($tasting, $agency, 4000);

        $this->book($tasting, '2026-06-10 11:00', 10, $agency, ReservationStatus::Completed); // 40 000, list 50 000
        $this->book($tasting, '2026-05-10 11:00', 5, $agency, ReservationStatus::Completed);  // previous period: 20 000

        $result = app(AgencyPerformanceQuery::class)->get(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), true);

        $this->assertSame('2026-05-02', $result['previous_from']);
        $this->assertSame('2026-05-31', $result['previous_to']);
        $row = $result['rows'][0];
        $this->assertSame(40000, $row['revenue']['minor']);
        $this->assertSame(50000, $row['list_value']['minor']);
        $this->assertSame(10000, $row['discount']['minor']);
        $this->assertSame(20.0, $row['discount_percent']);
        $this->assertSame(20000, $row['previous_revenue']['minor']);
        $this->assertSame(100.0, $row['change_percent']);
    }

    public function test_the_customer_tab_lists_every_active_program_with_this_agencys_price_and_its_bookings(): void
    {
        $tasting = $this->program(5000);
        $this->program(8000, ['name' => 'Vineyard tour']);
        $this->program(3000, ['name' => 'Retired', 'is_active' => false]);
        $agency = $this->customer('Adriatic Tours', agency: true);
        app(SetAgencyPriceAction::class)->execute($tasting, $agency, 4000);
        $this->book($tasting, '2026-06-10 11:00', 10, $agency);
        $this->book($tasting, '2026-06-12 11:00', 3, $agency, ReservationStatus::Cancelled);

        $tab = app(CustomerHospitalityQuery::class)->get($agency);

        $this->assertSame(['Classic tasting', 'Vineyard tour'], array_column($tab['prices'], 'program_name'));
        $this->assertSame(4000, $tab['prices'][0]['price_per_pax']['minor']);
        $this->assertSame(20.0, $tab['prices'][0]['effective_rebate_percent']);
        $this->assertNull($tab['prices'][1]['price_per_pax']);
        $this->assertSame(2, $tab['reservation_count']);
        $this->assertSame(40000, $tab['revenue']['minor']);
    }

    public function test_the_page_loads_analytics_only_when_asked(): void
    {
        $this->book($this->program(5000), '2026-06-10 11:00', 10);
        $this->forgetTenant();
        $session = [ActiveTenantSession::KEY => $this->tenant->getKey()];

        $this->actingAs($this->user)->withSession($session)->get('/hospitality?tab=analytics')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('analytics')->missing('agencyPerformance'));

        $this->actingAs($this->user)->withSession($session)
            ->get('/hospitality?tab=analytics&year=2026&perf_compare=1', $this->inertiaPartial('Hospitality/Index', ['analytics', 'agencyPerformance']))
            ->assertOk()
            ->assertJsonPath('props.analytics.year', 2026)
            ->assertJsonPath('props.analytics.kpis.reservations', 1)
            ->assertJsonPath('props.agencyPerformance.from', '2026-01-01')
            ->assertJsonPath('props.agencyPerformance.previous_to', '2025-12-31');
    }
}
