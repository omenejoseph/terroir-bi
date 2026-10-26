<?php

declare(strict_types=1);

namespace Tests\Feature\Hospitality;

use App\Actions\Hospitality\CreateReservationAction;
use App\Actions\Hospitality\SetAgencyPriceAction;
use App\Models\HospitalityAgencyPrice;
use App\Models\Reservation;
use App\Services\Customers\CustomerMergeService;

/**
 * Merging duplicate agencies must carry their reservations and price book across; deleting the
 * loser would otherwise null its reservations' customer and quietly turn them into walk-ins.
 */
class CustomerMergeReservationsTest extends HospitalityTestCase
{
    public function test_reservations_move_and_the_price_book_folds_per_program(): void
    {
        $tasting = $this->program(5000);
        $tour = $this->program(8000, ['name' => 'Vineyard tour']);
        $winner = $this->customer('Adriatic Tours', agency: true);
        $loser = $this->customer('Adriatic Tours d.o.o.', agency: true);

        $prices = app(SetAgencyPriceAction::class);
        $prices->execute($tasting, $winner, 4200);
        $prices->execute($tasting, $loser, 3900); // collides: the winner's price stands
        $prices->execute($tour, $loser, 7000);    // the winner has none: it moves over

        $reservation = app(CreateReservationAction::class)->execute([
            'date' => '2026-07-20 11:00',
            'pax' => 10,
            'program_id' => $tasting->getKey(),
            'customer_id' => $loser->getKey(),
            'guest_name' => 'Bus 1',
        ], $this->user->getKey());

        $result = app(CustomerMergeService::class)->merge($winner, [$loser->getKey()]);

        $this->assertSame(1, $result['totals']['reservations']);
        $this->assertSame(1, $result['totals']['agency_price_reassign']);
        $this->assertSame(1, $result['totals']['agency_price_drop']);

        $reservation = Reservation::query()->findOrFail($reservation->getKey());
        $this->assertSame($winner->getKey(), $reservation->customer_id);
        // The booking keeps the price it was taken at.
        $this->assertSame(3900, $reservation->price_per_pax->getMinorAmount());

        $book = HospitalityAgencyPrice::query()->where('customer_id', $winner->getKey())->get()->keyBy('program_id');
        $this->assertCount(2, $book);
        $this->assertSame(4200, $book[$tasting->getKey()]->price_per_pax->getMinorAmount());
        $this->assertSame(7000, $book[$tour->getKey()]->price_per_pax->getMinorAmount());
    }
}
