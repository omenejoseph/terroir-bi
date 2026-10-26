<?php

declare(strict_types=1);

namespace App\Actions\Hospitality;

use App\Exceptions\ReservationNotDeletableException;
use App\Models\Reservation;

class DeleteReservationAction
{
    /** @throws ReservationNotDeletableException when the reservation is past pending and not cancelled */
    public function execute(Reservation $reservation): void
    {
        if (! $reservation->status->isDeletable()) {
            throw ReservationNotDeletableException::for($reservation->status);
        }

        $reservation->delete();
    }
}
