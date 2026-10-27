<?php

declare(strict_types=1);

namespace Tests\Feature\Hospitality;

use App\Actions\Hospitality\CreateReservationAction;
use App\Actions\Hospitality\SetAgencyPriceAction;
use App\Enums\ReservationStatus;
use App\Enums\TenantRole;
use App\Models\HospitalityAgencyPrice;
use App\Models\HospitalityProgram;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Inertia hospitality pages: the reservation list and its tiles, the reservation page, the
 * Programs tab and the agency price book. Pricing itself is pinned by ReservationPricingTest; these
 * tests are about the transport — role gates, filters, what the server decides and what it refuses.
 */
class WebHospitalityTest extends HospitalityTestCase
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

    /** @param array<string, mixed> $overrides */
    private function book(HospitalityProgram $program, array $overrides = []): Reservation
    {
        return app(CreateReservationAction::class)->execute(array_merge([
            'date' => '2026-07-20 11:00',
            'pax' => 10,
            'program_id' => $program->getKey(),
            'guest_name' => 'Ivana Horvat',
        ], $overrides), $this->user->getKey());
    }

    /**
     * One request as a member of the test tenant. `$data` is the body, or the headers for a GET.
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function hit(?User $user, string $method, string $uri, array $data = []): TestResponse
    {
        $this->forgetTenant();

        return $this->actingAs($user ?? $this->user)
            ->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->{$method}($uri, $data);
    }

    public function test_the_list_shows_this_months_reservations_soonest_first_with_the_tiles(): void
    {
        $program = $this->program(5000);
        $this->book($program, ['date' => '2026-07-28 15:00', 'guest_name' => 'Later group']);
        $this->book($program, ['date' => '2026-07-15 09:00', 'guest_name' => 'Today group', 'pax' => 4]);
        $this->book($program, ['date' => '2026-08-02 11:00', 'guest_name' => 'Next month']);
        // Last year, same day: the comparison for the "today" tile.
        $this->book($program, ['date' => '2025-07-15 11:00', 'pax' => 2]);

        $this->hit(null, 'get', '/hospitality')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Hospitality/Index')
                ->where('filters.period', 'month')
                ->has('reservations.data', 2)
                ->where('reservations.data.0.guest_name', 'Today group')
                ->where('reservations.data.1.guest_name', 'Later group')
                ->where('stats.today.count', 1)
                ->where('stats.today.revenue.minor', 20000)
                ->where('stats.today.previous_revenue.minor', 10000)
                ->where('stats.today.delta_percent', 100)
                // Month to date stops at today; the confirmed share looks at the whole month.
                ->where('stats.month.count', 1)
                ->where('stats.booked', 2)
                ->where('stats.confirmed', 0));
    }

    public function test_cancelled_reservations_and_excluded_customers_stay_out_of_the_tiles(): void
    {
        $program = $this->program(5000);
        $excluded = $this->customer('House account', agency: true, attributes: ['exclude_from_stats' => true]);
        $this->book($program, ['date' => '2026-07-15 09:00', 'customer_id' => $excluded->getKey()]);
        $cancelled = $this->book($program, ['date' => '2026-07-15 12:00']);
        $cancelled->forceFill(['status' => ReservationStatus::Cancelled])->save();
        $this->book($program, ['date' => '2026-07-15 16:00', 'pax' => 3]);

        $this->hit(null, 'get', '/hospitality')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The list still shows all three; only the figures leave them out.
                ->has('reservations.data', 3)
                ->where('stats.today.count', 1)
                ->where('stats.today.pax', 3)
                ->where('stats.today.revenue.minor', 15000));
    }

    public function test_filters_narrow_the_list(): void
    {
        $tasting = $this->program(5000);
        $tour = $this->program(8000, ['name' => 'Vineyard tour']);
        $agency = $this->customer('Adriatic Tours', agency: true);
        $this->book($tasting, ['guest_name' => 'Walk-in couple', 'pax' => 2]);
        $this->book($tour, ['guest_name' => 'Agency bus', 'customer_id' => $agency->getKey()]);
        $confirmed = $this->book($tasting, ['guest_name' => 'Confirmed group']);
        $confirmed->forceFill(['status' => ReservationStatus::Confirmed])->save();

        $names = fn (string $query): array => array_column(
            (array) $this->hit(null, 'get', '/hospitality?'.$query)->assertOk()->viewData('page')['props']['reservations']['data'],
            'guest_name',
        );

        $this->assertSame(['Agency bus'], $names('customer_id='.$agency->getKey()));
        $this->assertEqualsCanonicalizing(['Walk-in couple', 'Confirmed group'], $names('customer_id=walk_in'));
        $this->assertSame(['Agency bus'], $names('program_id='.$tour->getKey()));
        $this->assertSame(['Confirmed group'], $names('status=CONFIRMED'));
        $this->assertSame(['Walk-in couple'], $names('search=couple'));
        $this->assertSame([], $names('period=year&from=2026-01-01&to=2026-06-30'));
    }

    public function test_a_new_reservation_is_priced_by_the_server_and_opens_its_page(): void
    {
        $program = $this->program(5000);
        $agency = $this->customer('Adriatic Tours', agency: true);
        app(SetAgencyPriceAction::class)->execute($program, $agency, 4200);

        $response = $this->hit(null, 'post', '/hospitality/reservations', [
            'program_id' => $program->getKey(),
            'customer_id' => $agency->getKey(),
            'date' => '2026-07-20 11:00',
            'pax' => 12,
            'guest_name' => 'Adriatic group',
            // Whatever a client sends for money is ignored.
            'price_per_pax' => 1,
            'total_amount' => 1,
        ]);

        $this->actingAsTenant($this->tenant);
        $reservation = Reservation::query()->firstOrFail();
        $response->assertRedirect('/hospitality/reservations/'.$reservation->getKey());

        $this->assertSame(4200, $reservation->price_per_pax->getMinorAmount());
        $this->assertSame(50400, $reservation->total_amount->getMinorAmount());
        $this->assertSame('2026-07-20 11:00', $reservation->date->format('Y-m-d H:i'));

        $this->hit(null, 'get', '/hospitality/reservations/'.$reservation->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Hospitality/Reservation')
                ->where('reservation.date', '2026-07-20 11:00')
                ->where('reservation.customer.name', 'Adriatic Tours')
                ->where('reservation.profitability.list_total.minor', 60000)
                ->where('reservation.profitability.rebate_percent', 16)
                ->where('reservation.next_status', 'CONFIRMED')
                ->has('reservation.history', 1));
    }

    public function test_a_retired_program_cannot_take_new_bookings(): void
    {
        $program = $this->program(5000, ['is_active' => false]);

        $this->hit(null, 'post', '/hospitality/reservations', [
            'program_id' => $program->getKey(),
            'date' => '2026-07-20 11:00',
            'pax' => 4,
            'guest_name' => 'Late',
        ])->assertSessionHasErrors('program_id');
    }

    public function test_the_quote_names_where_the_price_came_from(): void
    {
        $program = $this->program(5000, ['max_pax' => 20]);
        $agency = $this->customer('Adriatic Tours', agency: true);
        $direct = $this->customer('Hotel Esplanade', rebate: 10);
        app(SetAgencyPriceAction::class)->execute($program, $agency, 4000);

        $quote = fn (array $query) => $this->hit(null, 'get', '/hospitality/reservations/quote?'.http_build_query($query))->assertOk();

        $quote(['program_id' => $program->getKey(), 'pax' => 5])
            ->assertJson(['source' => 'base_price', 'price_per_pax' => ['minor' => 5000], 'total' => ['minor' => 25000], 'max_pax' => 20]);
        $quote(['program_id' => $program->getKey(), 'customer_id' => $agency->getKey(), 'pax' => 5])
            ->assertJson(['source' => 'agency_price', 'price_per_pax' => ['minor' => 4000], 'rebate_percent' => 20]);
        $quote(['program_id' => $program->getKey(), 'customer_id' => $direct->getKey(), 'pax' => 2])
            ->assertJson(['source' => 'customer_rebate', 'price_per_pax' => ['minor' => 4500], 'total' => ['minor' => 9000]]);
    }

    public function test_status_notes_and_the_delete_guard_go_through_the_actions(): void
    {
        $reservation = $this->book($this->program(5000));
        $url = '/hospitality/reservations/'.$reservation->getKey();

        $this->hit(null, 'post', $url.'/status', ['status' => 'CONFIRMED', 'note' => 'Deposit agreed'])->assertRedirect();
        $this->hit(null, 'post', $url.'/notes', ['note' => 'Vegetarian menu for two'])->assertRedirect();

        // Past pending and not cancelled: kept, with the reason flashed.
        $this->hit(null, 'delete', $url)->assertRedirect()->assertSessionHas('error');

        $this->actingAsTenant($this->tenant);
        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(
            // The first note is written in the language the booking was made in (the tenant's).
            [['PENDING', __('Reservation created', [], $this->tenant->default_locale)], ['CONFIRMED', 'Deposit agreed'], ['CONFIRMED', 'Vegetarian menu for two']],
            $reservation->statusHistories->map(fn ($h) => [$h->status->value, $h->note])->all(),
        );

        $this->hit(null, 'post', $url.'/status', ['status' => 'CANCELLED', 'note' => 'Bus broke down'])->assertRedirect();
        $this->hit(null, 'delete', $url)->assertRedirect('/hospitality');

        $this->assertDatabaseMissing('reservations', ['id' => $reservation->getKey()]);
        $this->assertDatabaseMissing('reservation_status_histories', ['reservation_id' => $reservation->getKey()]);
    }

    public function test_editing_only_the_date_keeps_the_price_and_changing_pax_reprices(): void
    {
        $program = $this->program(5000);
        $reservation = $this->book($program, ['pax' => 10]);
        $this->actingAsTenant($this->tenant);
        $program->update(['base_price' => 6000]);

        $url = '/hospitality/reservations/'.$reservation->getKey();
        $this->hit(null, 'patch', $url, ['date' => '2026-07-22 15:30'])->assertRedirect();

        $this->actingAsTenant($this->tenant);
        $this->assertSame(5000, $reservation->refresh()->price_per_pax->getMinorAmount());

        $this->hit(null, 'patch', $url, ['pax' => 8])->assertRedirect();

        $this->actingAsTenant($this->tenant);
        $reservation->refresh();
        $this->assertSame(6000, $reservation->price_per_pax->getMinorAmount());
        $this->assertSame(48000, $reservation->total_amount->getMinorAmount());
    }

    public function test_programs_and_the_agency_price_book_are_managed_from_the_programs_tab(): void
    {
        $this->hit(null, 'post', '/hospitality/programs', [
            'name' => 'Vineyard tour',
            'base_price' => 8000,
            'duration_mins' => 90,
            'max_pax' => 30,
        ])->assertRedirect();

        $this->actingAsTenant($this->tenant);
        $program = HospitalityProgram::query()->where('name', 'Vineyard tour')->firstOrFail();
        $agency = $this->customer('Adriatic Tours', agency: true);
        $direct = $this->customer('Hotel Esplanade');

        $priceUrl = fn ($customer): string => '/hospitality/programs/'.$program->getKey().'/agency-prices/'.$customer->getKey();

        // A rebate is worked out from base and kept, so it follows the base price.
        $this->hit(null, 'put', $priceUrl($agency), ['rebate_percent' => 12.5])->assertRedirect();
        $this->actingAsTenant($this->tenant);
        $this->assertSame(7000, HospitalityAgencyPrice::query()->firstOrFail()->price_per_pax->getMinorAmount());

        $this->hit(null, 'patch', '/hospitality/programs/'.$program->getKey(), ['name' => 'Vineyard tour', 'base_price' => 10000])->assertRedirect();
        $this->actingAsTenant($this->tenant);
        $this->assertSame(8750, HospitalityAgencyPrice::query()->firstOrFail()->price_per_pax->getMinorAmount());

        // Only agencies have a price book.
        $this->hit(null, 'put', $priceUrl($direct), ['price_per_pax' => 5000])->assertStatus(422);

        $this->hit(null, 'get', '/hospitality?tab=programs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('programs.0.agency_price_count', 1)
                // The price book is only paid for while its drawer is open.
                ->missing('programPrices'));

        $this->hit(null, 'get', '/hospitality?program='.$program->getKey(), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'Hospitality/Index',
            'X-Inertia-Partial-Data' => 'programPrices',
        ])
            ->assertOk()
            ->assertJsonPath('props.programPrices.0.customer_name', 'Adriatic Tours')
            ->assertJsonPath('props.programPrices.0.rebate_percent', 12.5)
            ->assertJsonPath('props.programPrices.0.price_per_pax.minor', 8750);

        $this->hit(null, 'delete', $priceUrl($agency))->assertRedirect();
        $this->assertDatabaseCount('hospitality_agency_prices', 0);
    }

    public function test_only_admin_and_hospitality_reach_the_pages(): void
    {
        $orders = $this->createMember($this->tenant, [TenantRole::Orders]);
        $admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->hit($orders, 'get', '/hospitality')->assertForbidden();
        $this->hit($orders, 'post', '/hospitality/programs', ['name' => 'X', 'base_price' => 100])->assertForbidden();
        $this->hit($admin, 'get', '/hospitality')->assertOk();
    }

    public function test_another_tenants_reservation_is_not_found(): void
    {
        $other = $this->createTenant();
        $this->actingAsTenant($other);
        $otherProgram = HospitalityProgram::create(['name' => 'Elsewhere', 'base_price' => 1000]);
        $foreign = app(CreateReservationAction::class)->execute([
            'date' => '2026-07-20 11:00',
            'pax' => 2,
            'program_id' => $otherProgram->getKey(),
        ], $this->user->getKey());

        $this->hit(null, 'get', '/hospitality/reservations/'.$foreign->getKey())->assertNotFound();
        $this->hit(null, 'post', '/hospitality/reservations/'.$foreign->getKey().'/status', ['status' => 'PAID'])->assertNotFound();
    }
}
