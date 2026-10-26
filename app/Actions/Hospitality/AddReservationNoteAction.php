<?php

declare(strict_types=1);

namespace App\Actions\Hospitality;

use App\Models\Reservation;
use App\Models\ReservationStatusHistory;

class AddReservationNoteAction
{
    /** A note is a timeline line that carries the status the reservation is in right now. */
    public function execute(Reservation $reservation, string $note, string $changedById): ReservationStatusHistory
    {
        return $reservation->statusHistories()->create([
            'status' => $reservation->status,
            'note' => $note,
            'changed_by_id' => $changedById,
        ]);
    }
}
