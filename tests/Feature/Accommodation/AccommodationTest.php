<?php

declare(strict_types=1);

namespace Tests\Feature\Accommodation;

use App\Actions\Accommodation\SaveVillaAction;
use App\Actions\Accommodation\SaveVillaBookingAction;
use App\Actions\Accommodation\SaveVillaMenuItemAction;
use App\Enums\TenantRole;
use App\Enums\VillaBookingStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Villa;
use App\Models\VillaBooking;
use App\Models\VillaMenuItem;
use App\Queries\AccommodationOverviewQuery;
use App\Services\Accommodation\BookingPricing;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Villas, the one booking-pricing rule, booking rules, the menu and the overview (docs/21 §4, 3a). */
class AccommodationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-01 09:00:00');
        $this->tenant = $this->createTenant();
        $this->host = $this->createMember($this->tenant, [TenantRole::Hospitality]);
        $this->actingAsTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function villa(string $name = 'Villa Maslina'): Villa
    {
        $villa = app(SaveVillaAction::class)->execute(['name' => $name]);
        $villa->rates()->create(['label' => 'Shoulder', 'start_date' => '2026-06-01', 'end_date' => '2026-07-10', 'nightly_rate' => 20000]);
        $villa->rates()->create(['label' => 'Peak', 'start_date' => '2026-07-05', 'end_date' => '2026-08-31', 'nightly_rate' => 30000]);

        return $villa;
    }

    /** @param array<string, mixed> $overrides */
    private function book(Villa $villa, array $overrides = []): VillaBooking
    {
        return app(SaveVillaBookingAction::class)->execute(array_merge([
            'villa_id' => $villa->getKey(),
            'guest_name' => 'Anna Weber',
            'check_in' => '2026-07-03',
            'check_out' => '2026-07-07',
            'guests' => 4,
            'rebate_percent' => 0,
        ], $overrides));
    }

    public function test_each_night_takes_the_latest_starting_rate_that_covers_it_and_gaps_are_reported(): void
    {
        $villa = $this->villa();

        // 3rd and 4th: shoulder only. 5th and 6th: both cover, peak starts later and wins.
        $quote = app(BookingPricing::class)->price($villa, '2026-07-03', '2026-07-07');
        $this->assertSame(4, $quote['nights']);
        $this->assertSame(20000 * 2 + 30000 * 2, $quote['subtotal']);
        $this->assertSame([], $quote['missing_nights']);

        // The end date is inclusive: 31 August is still peak, 1 September has no rate.
        $edge = app(BookingPricing::class)->price($villa, '2026-08-31', '2026-09-02', 12.5);
        $this->assertSame(30000, $edge['subtotal']);
        $this->assertSame(['2026-09-01'], $edge['missing_nights']);
        $this->assertSame(3750, $edge['rebate']);
        $this->assertSame(26250, $edge['total']);
    }

    public function test_a_new_booking_keeps_the_rate_price_and_an_edit_keeps_it_unless_recalculated(): void
    {
        $villa = $this->villa();
        $booking = $this->book($villa);

        $this->assertSame(100000, $booking->auto_calculated_amount?->getMinorAmount());
        $this->assertSame(100000, $booking->total_amount?->getMinorAmount());
        $this->assertNotSame('', $booking->portal_token);

        // New dates alone do not re-price, as in the old app…
        $booking = app(SaveVillaBookingAction::class)->execute(['check_in' => '2026-07-03', 'check_out' => '2026-07-05', 'villa_id' => $villa->getKey(), 'guest_name' => 'Anna Weber', 'guests' => 4], $booking);
        $this->assertSame(100000, $booking->total_amount?->getMinorAmount());

        // …unless asked to; and a typed total wins over the rates.
        $booking = app(SaveVillaBookingAction::class)->execute(['check_in' => '2026-07-03', 'check_out' => '2026-07-05', 'villa_id' => $villa->getKey(), 'guest_name' => 'Anna Weber', 'guests' => 4, 'recalculate' => true], $booking);
        $this->assertSame(40000, $booking->total_amount?->getMinorAmount());

        $override = $this->book($villa, ['check_in' => '2026-08-01', 'check_out' => '2026-08-03', 'total_amount' => 55000]);
        $this->assertSame(60000, $override->auto_calculated_amount?->getMinorAmount());
        $this->assertSame(55000, $override->total_amount?->getMinorAmount());
    }

    public function test_overlapping_live_bookings_of_one_villa_are_refused_but_cancelled_ones_do_not_block(): void
    {
        $villa = $this->villa();
        $other = $this->villa('Villa Smokva');
        $first = $this->book($villa);

        // Same villa, overlapping nights.
        try {
            $this->book($villa, ['check_in' => '2026-07-06', 'check_out' => '2026-07-09']);
            $this->fail('An overlapping booking was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('check_in', $e->errors());
        }

        // Check-out day = next check-in day is fine; another villa is fine.
        $this->book($villa, ['check_in' => '2026-07-07', 'check_out' => '2026-07-09']);
        $this->book($other, ['check_in' => '2026-07-04', 'check_out' => '2026-07-06']);

        $first->update(['status' => VillaBookingStatus::Cancelled]);
        $this->book($villa, ['check_in' => '2026-07-04', 'check_out' => '2026-07-06']);

        $this->assertSame(4, VillaBooking::query()->count());
    }

    public function test_check_out_must_follow_check_in(): void
    {
        $villa = $this->villa();
        $this->forgetTenant();

        $this->actingAs($this->host)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->post('/accommodation/bookings', [
                'villa_id' => $villa->getKey(), 'guest_name' => 'X', 'check_in' => '2026-07-05', 'check_out' => '2026-07-05', 'guests' => 2,
            ])
            ->assertSessionHasErrors('check_out');
    }

    public function test_villa_slugs_stay_unique_and_the_portal_token_never_changes(): void
    {
        $a = app(SaveVillaAction::class)->execute(['name' => 'Villa Maslina']);
        $b = app(SaveVillaAction::class)->execute(['name' => 'Villa Maslina']);
        $this->assertSame('villa-maslina', $a->slug);
        $this->assertSame('villa-maslina-2', $b->slug);

        $token = $a->portal_token;
        app(SaveVillaAction::class)->execute(['name' => 'Villa Maslina Nova'], $a);
        $this->assertSame('villa-maslina-nova', $a->refresh()->slug);
        $this->assertSame($token, $a->portal_token);
    }

    public function test_a_menu_item_can_be_added_to_every_active_villa_at_the_end_of_its_category(): void
    {
        $one = $this->villa('One');
        $this->villa('Two');
        $retired = $this->villa('Retired');
        $retired->update(['is_active' => false]);
        VillaMenuItem::create(['villa_id' => $one->getKey(), 'category' => 'Breakfast', 'name' => 'Eggs', 'price' => 900, 'sort_order' => 4]);

        $created = app(SaveVillaMenuItemAction::class)->execute(['category' => 'Breakfast', 'name' => 'Pancakes', 'price' => 1200], null, true);

        $this->assertCount(2, $created);
        $this->assertSame(5, (int) VillaMenuItem::query()->where('villa_id', $one->getKey())->where('name', 'Pancakes')->value('sort_order'));
    }

    public function test_the_overview_counts_owed_deposits_arrivals_and_occupancy(): void
    {
        $villa = $this->villa();
        $this->book($villa, ['check_in' => '2026-07-01', 'check_out' => '2026-07-04', 'deposit_amount' => 30000, 'deposit_paid' => true]);
        $this->book($villa, ['check_in' => '2026-07-05', 'check_out' => '2026-07-08', 'deposit_amount' => 25000]);
        $cancelled = $this->book($villa, ['check_in' => '2026-07-10', 'check_out' => '2026-07-12', 'deposit_amount' => 99900]);
        $cancelled->update(['status' => VillaBookingStatus::Cancelled]);

        $o = app(AccommodationOverviewQuery::class)->get();

        $this->assertSame(25000, $o['deposits_owed']['minor']);
        $this->assertCount(1, $o['arrivals_today']);
        $this->assertCount(1, $o['arrivals_week']);
        // 3 + 3 booked nights of the next 30.
        $this->assertSame(6, $o['occupancy'][0]['booked_nights']);
        $this->assertSame(20.0, $o['occupancy'][0]['percent']);
    }

    public function test_hospitality_and_admin_reach_it_others_do_not(): void
    {
        $kitchen = $this->createMember($this->tenant, [TenantRole::Kitchen]);
        $this->forgetTenant();
        $session = [ActiveTenantSession::KEY => $this->tenant->getKey()];

        $this->actingAs($this->host)->withSession($session)->get('/accommodation')->assertOk();
        $this->actingAs($kitchen)->withSession($session)->get('/accommodation')->assertForbidden();
        $this->actingAs($kitchen)->withSession($session)->post('/accommodation/villas', ['name' => 'X'])->assertForbidden();
    }
}
