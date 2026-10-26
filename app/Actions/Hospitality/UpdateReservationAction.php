<?php

declare(strict_types=1);

namespace App\Actions\Hospitality;

use App\Models\Customer;
use App\Models\HospitalityProgram;
use App\Models\Reservation;
use App\Services\Hospitality\ReservationPricing;
use App\Support\Money\Money;

class UpdateReservationAction
{
    public function __construct(private readonly ReservationPricing $pricing) {}

    /**
     * Edits the booking. The price is taken again only when the program, the agency or the head
     * count changed — a new date or a note never re-prices a reservation.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Reservation $reservation, array $data): Reservation
    {
        $reservation->fill($data);

        if ($reservation->isDirty(['program_id', 'customer_id', 'pax'])) {
            $program = HospitalityProgram::query()->findOrFail($reservation->program_id);
            $customer = $reservation->customer_id ? Customer::query()->findOrFail($reservation->customer_id) : null;

            $pricePerPax = $this->pricing->pricePerPax($program, $customer);
            $currency = $program->base_price->getCurrency();
            $reservation->price_per_pax = Money::fromMinor($pricePerPax, $currency);
            $reservation->total_amount = Money::fromMinor($this->pricing->total($pricePerPax, $reservation->pax), $currency);
        }

        $reservation->save();

        return $reservation;
    }
}
