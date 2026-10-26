<?php

declare(strict_types=1);

namespace Tests\Feature\Hospitality;

use App\Actions\Hospitality\CreateProgramAction;
use App\Actions\Hospitality\RemoveAgencyPriceAction;
use App\Actions\Hospitality\SetAgencyPriceAction;
use App\Actions\Hospitality\UpdateProgramAction;
use App\Models\HospitalityAgencyPrice;

class ProgramActionsTest extends HospitalityTestCase
{
    public function test_new_programs_go_to_the_end_of_the_list(): void
    {
        $first = app(CreateProgramAction::class)->execute(['name' => 'Classic', 'base_price' => 5000]);
        $second = app(CreateProgramAction::class)->execute(['name' => 'Premium', 'base_price' => 9000]);

        $this->assertSame(1, $first->sort_order);
        $this->assertSame(2, $second->sort_order);
        $this->assertTrue($first->is_active);
    }

    public function test_setting_an_agency_price_twice_replaces_it(): void
    {
        $program = $this->program();
        $agency = $this->customer('Adriatic Tours', agency: true);

        app(SetAgencyPriceAction::class)->execute($program, $agency, 4200);
        app(SetAgencyPriceAction::class)->execute($program, $agency, 4000, 20);

        $this->assertSame(1, HospitalityAgencyPrice::count());
        $price = HospitalityAgencyPrice::query()->firstOrFail();
        $this->assertSame(4000, $price->price_per_pax->getMinorAmount());
        $this->assertSame('20.00', $price->rebate_percent);
    }

    public function test_a_zero_rebate_is_stored_as_no_rebate(): void
    {
        $price = app(SetAgencyPriceAction::class)->execute($this->program(), $this->customer('A', agency: true), 4200, 0);

        $this->assertNull($price->refresh()->rebate_percent);
    }

    public function test_changing_the_base_price_re_derives_rebate_based_agency_prices_only(): void
    {
        $program = $this->program(5000);
        $rebated = $this->customer('Rebated', agency: true);
        $flat = $this->customer('Flat', agency: true);
        app(SetAgencyPriceAction::class)->execute($program, $rebated, 4500, 10);
        app(SetAgencyPriceAction::class)->execute($program, $flat, 4000);

        app(UpdateProgramAction::class)->execute($program, ['base_price' => 6000]);

        $price = fn ($c) => HospitalityAgencyPrice::where('customer_id', $c->getKey())->firstOrFail()->price_per_pax->getMinorAmount();
        $this->assertSame(5400, $price($rebated));
        $this->assertSame(4000, $price($flat));
    }

    public function test_editing_something_other_than_the_base_price_leaves_agency_prices_alone(): void
    {
        $program = $this->program(5000);
        $agency = $this->customer('Rebated', agency: true);
        app(SetAgencyPriceAction::class)->execute($program, $agency, 4300, 10); // deliberately not 4500

        app(UpdateProgramAction::class)->execute($program, ['name' => 'Renamed']);

        $this->assertSame(4300, HospitalityAgencyPrice::query()->firstOrFail()->price_per_pax->getMinorAmount());
    }

    public function test_a_program_can_be_deactivated_without_losing_it(): void
    {
        $program = $this->program();

        app(UpdateProgramAction::class)->execute($program, ['is_active' => false]);

        $this->assertFalse($program->refresh()->is_active);
    }

    public function test_removing_an_agency_price_deletes_only_that_entry(): void
    {
        $program = $this->program();
        $a = app(SetAgencyPriceAction::class)->execute($program, $this->customer('A', agency: true), 4000);
        app(SetAgencyPriceAction::class)->execute($program, $this->customer('B', agency: true), 4100);

        app(RemoveAgencyPriceAction::class)->execute($a);

        $this->assertSame(1, HospitalityAgencyPrice::count());
    }
}
