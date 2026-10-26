<?php

declare(strict_types=1);

namespace Tests\Feature\Hospitality;

use App\Actions\Hospitality\AddReservationNoteAction;
use App\Actions\Hospitality\ChangeReservationStatusAction;
use App\Actions\Hospitality\CreateReservationAction;
use App\Actions\Hospitality\DeleteReservationAction;
use App\Actions\Hospitality\SetAgencyPriceAction;
use App\Actions\Hospitality\UpdateReservationAction;
use App\Enums\ReservationStatus;
use App\Exceptions\ReservationNotDeletableException;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;

class ReservationActionsTest extends HospitalityTestCase
{
    /** @param array<string, mixed> $overrides */
    private function book(array $overrides = []): Reservation
    {
        $data = array_merge([
            'date' => '2026-07-10 11:00:00',
            'pax' => 10,
            'program_id' => $this->program(5000)->getKey(),
            'guest_name' => 'Ivana Horvat',
        ], $overrides);

        return app(CreateReservationAction::class)->execute($data, $this->user->getKey());
    }

    public function test_a_walk_in_booking_is_priced_at_base_and_starts_pending_with_a_history_line(): void
    {
        $reservation = $this->book();

        $this->assertSame(ReservationStatus::Pending, $reservation->status);
        $this->assertSame(5000, $reservation->price_per_pax->getMinorAmount());
        $this->assertSame(50000, $reservation->total_amount->getMinorAmount());
        $this->assertNull($reservation->customer_id);
        $this->assertCount(1, $reservation->statusHistories);
        $this->assertSame(ReservationStatus::Pending, $reservation->statusHistories->firstOrFail()->status);
        $this->assertSame($this->user->getKey(), $reservation->statusHistories->firstOrFail()->changed_by_id);
    }

    public function test_an_agency_booking_uses_the_price_book(): void
    {
        $program = $this->program(5000);
        $agency = $this->customer('Adriatic Tours', agency: true);
        app(SetAgencyPriceAction::class)->execute($program, $agency, 4200);

        $reservation = $this->book(['program_id' => $program->getKey(), 'customer_id' => $agency->getKey(), 'pax' => 12]);

        $this->assertSame(4200, $reservation->price_per_pax->getMinorAmount());
        $this->assertSame(50400, $reservation->total_amount->getMinorAmount());
    }

    public function test_changing_the_date_or_notes_does_not_re_price(): void
    {
        $program = $this->program(5000);
        $reservation = $this->book(['program_id' => $program->getKey()]);
        $program->update(['base_price' => 9000]); // price list moved after the booking was taken

        app(UpdateReservationAction::class)->execute($reservation, ['date' => '2026-08-01 12:00:00', 'notes' => 'Window table']);

        $this->assertSame(5000, $reservation->refresh()->price_per_pax->getMinorAmount());
        $this->assertSame('Window table', $reservation->refresh()->notes);
    }

    public function test_changing_the_head_count_re_prices_at_todays_price_book(): void
    {
        $program = $this->program(5000);
        $reservation = $this->book(['program_id' => $program->getKey(), 'pax' => 10]);
        $program->update(['base_price' => 6000]);

        app(UpdateReservationAction::class)->execute($reservation, ['pax' => 20]);

        $fresh = $reservation->refresh();
        $this->assertSame(6000, $fresh->price_per_pax->getMinorAmount());
        $this->assertSame(120000, $fresh->total_amount->getMinorAmount());
    }

    public function test_changing_the_agency_re_prices(): void
    {
        $program = $this->program(5000);
        $agency = $this->customer('Adriatic Tours', agency: true);
        app(SetAgencyPriceAction::class)->execute($program, $agency, 4000);
        $reservation = $this->book(['program_id' => $program->getKey()]);

        app(UpdateReservationAction::class)->execute($reservation, ['customer_id' => $agency->getKey()]);

        $this->assertSame(40000, $reservation->refresh()->total_amount->getMinorAmount());
    }

    public function test_a_status_change_writes_history_and_a_cancel_note_becomes_the_cancel_reason(): void
    {
        $reservation = $this->book();
        $action = app(ChangeReservationStatusAction::class);

        $action->execute($reservation, ReservationStatus::Confirmed, null, $this->user->getKey());
        $this->assertNull($reservation->refresh()->cancel_reason);

        $action->execute($reservation, ReservationStatus::Cancelled, 'Group fell through', $this->user->getKey());

        $fresh = $reservation->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $fresh->status);
        $this->assertSame('Group fell through', $fresh->cancel_reason);
        $this->assertSame(
            ['PENDING', 'CONFIRMED', 'CANCELLED'],
            $fresh->statusHistories->map(fn ($h) => $h->status->value)->all(),
        );
    }

    public function test_the_old_app_accepted_any_status_so_skipping_ahead_works(): void
    {
        $reservation = $this->book();

        app(ChangeReservationStatusAction::class)->execute($reservation, ReservationStatus::Paid, null, $this->user->getKey());

        $this->assertSame(ReservationStatus::Paid, $reservation->refresh()->status);
    }

    public function test_a_note_is_a_history_line_in_the_current_status(): void
    {
        $reservation = $this->book();
        app(ChangeReservationStatusAction::class)->execute($reservation, ReservationStatus::Confirmed, null, $this->user->getKey());

        app(AddReservationNoteAction::class)->execute($reservation->refresh(), 'Vegetarian menu requested', $this->user->getKey());

        $last = $reservation->refresh()->statusHistories->sole(fn ($h) => $h->note === 'Vegetarian menu requested');
        $this->assertSame('Vegetarian menu requested', $last->note);
        $this->assertSame(ReservationStatus::Confirmed, $last->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status, 'a note does not change the status');
    }

    public function test_only_pending_or_cancelled_reservations_can_be_deleted(): void
    {
        $delete = app(DeleteReservationAction::class);

        $pending = $this->book();
        $delete->execute($pending);
        $this->assertNull(Reservation::find($pending->getKey()));

        $cancelled = $this->book();
        $cancelled->update(['status' => ReservationStatus::Cancelled]);
        $delete->execute($cancelled);
        $this->assertNull(Reservation::find($cancelled->getKey()));

        $confirmed = $this->book();
        $confirmed->update(['status' => ReservationStatus::Confirmed]);
        $this->expectException(ReservationNotDeletableException::class);
        $delete->execute($confirmed);
    }

    public function test_deleting_a_reservation_removes_its_history(): void
    {
        $reservation = $this->book();
        $id = $reservation->getKey();

        app(DeleteReservationAction::class)->execute($reservation);

        $this->assertSame(0, ReservationStatusHistory::where('reservation_id', $id)->count());
    }

    public function test_deleting_an_agency_keeps_its_reservations_as_walk_ins(): void
    {
        $agency = $this->customer('Adriatic Tours', agency: true);
        $reservation = $this->book(['customer_id' => $agency->getKey()]);

        $agency->delete();

        $this->assertNull($reservation->refresh()->customer_id);
    }

    public function test_the_status_flow_walks_forward_and_stops(): void
    {
        $this->assertSame(ReservationStatus::Confirmed, ReservationStatus::Pending->next());
        $this->assertSame(ReservationStatus::Completed, ReservationStatus::Paid->next());
        $this->assertNull(ReservationStatus::Completed->next());
        $this->assertNull(ReservationStatus::Cancelled->next());
    }
}
