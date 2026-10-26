<?php

declare(strict_types=1);

namespace App\Actions\Hospitality;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChangeReservationStatusAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Any status can be set — the old app trusted the user and did not validate transitions. The
     * note is kept on the timeline; when cancelling, it is also the reservation's cancel reason.
     */
    public function execute(Reservation $reservation, ReservationStatus $status, ?string $note, string $changedById): Reservation
    {
        $from = $reservation->status;

        DB::transaction(function () use ($reservation, $status, $note, $changedById): void {
            $reservation->status = $status;

            if ($status === ReservationStatus::Cancelled && $note !== null && $note !== '') {
                $reservation->cancel_reason = $note;
            }

            $reservation->save();

            $reservation->statusHistories()->create([
                'status' => $status,
                'note' => $note,
                'changed_by_id' => $changedById,
            ]);
        });

        $this->audit->record(Auth::user(), 'reservation.status_changed', $reservation, [
            'from' => $from->value,
            'to' => $status->value,
            'note' => $note,
        ]);

        return $reservation;
    }
}
