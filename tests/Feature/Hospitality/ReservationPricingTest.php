<?php

declare(strict_types=1);

namespace Tests\Feature\Hospitality;

use App\Actions\Hospitality\SetAgencyPriceAction;
use App\Services\Hospitality\ReservationPricing;

class ReservationPricingTest extends HospitalityTestCase
{
    private function pricing(): ReservationPricing
    {
        return app(ReservationPricing::class);
    }

    public function test_a_walk_in_pays_the_base_price(): void
    {
        $this->assertSame(5000, $this->pricing()->pricePerPax($this->program(5000), null));
    }

    public function test_an_agency_with_a_price_book_entry_pays_that_price(): void
    {
        $program = $this->program(5000);
        $agency = $this->customer('Adriatic Tours', agency: true);
        app(SetAgencyPriceAction::class)->execute($program, $agency, 4200);

        $this->assertSame(4200, $this->pricing()->pricePerPax($program, $agency));
    }

    public function test_an_agency_without_an_entry_pays_the_base_price_even_with_a_customer_rebate(): void
    {
        $agency = $this->customer('Adriatic Tours', agency: true, rebate: 20);

        $this->assertSame(5000, $this->pricing()->pricePerPax($this->program(5000), $agency));
    }

    public function test_a_direct_customer_gets_their_standing_rebate(): void
    {
        $customer = $this->customer('Konoba Marin', rebate: 10);

        $this->assertSame(4500, $this->pricing()->pricePerPax($this->program(5000), $customer));
    }

    public function test_a_direct_customer_without_a_rebate_pays_the_base_price(): void
    {
        $this->assertSame(5000, $this->pricing()->pricePerPax($this->program(5000), $this->customer('Konoba Marin')));
    }

    public function test_a_price_book_entry_beats_a_direct_customers_rebate(): void
    {
        $program = $this->program(5000);
        $customer = $this->customer('Konoba Marin', rebate: 10);
        app(SetAgencyPriceAction::class)->execute($program, $customer, 3900);

        $this->assertSame(3900, $this->pricing()->pricePerPax($program, $customer));
    }

    public function test_rebates_round_half_up_to_a_whole_minor_unit(): void
    {
        // 33.33 minor units * 0.875 = 29.16..; 12.5 % off 4001 = 3500.875 -> 3501
        $this->assertSame(3501, $this->pricing()->afterRebate(4001, 12.5));
        $this->assertSame(0, $this->pricing()->afterRebate(5000, 100));
        $this->assertSame(5000, $this->pricing()->afterRebate(5000, 0));
        $this->assertSame(5000, $this->pricing()->afterRebate(5000, -5), 'a negative rebate never raises the price');
        $this->assertSame(0, $this->pricing()->afterRebate(5000, 140), 'a rebate over 100 % floors at free');
    }

    public function test_total_is_price_times_pax(): void
    {
        $this->assertSame(63000, $this->pricing()->total(4500, 14));
    }
}
