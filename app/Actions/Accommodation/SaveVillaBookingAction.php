<?php

declare(strict_types=1);

namespace App\Actions\Accommodation;

use App\Enums\VillaBookingStatus;
use App\Models\Villa;
use App\Models\VillaBooking;
use App\Services\Accommodation\BookingPricing;
use Illuminate\Validation\ValidationException;

class SaveVillaBookingAction
{
    public function __construct(private readonly BookingPricing $pricing) {}

    /**
     * Add or edit a stay.
     *
     * On a new booking the rates are applied and kept as `auto_calculated_amount`; the agreed total is
     * that figure unless one is typed in. Editing keeps both figures, as the old app did, unless
     * `recalculate` asks for the rates to be applied again. Two live bookings of one villa may not
     * overlap (the old app had no such check); a cancelled booking never blocks.
     *
     * @param  array<string, mixed>  $data  villa_id, guest_*, check_in, check_out, guests, notes, rebate_percent, total_amount?, deposit_amount?, deposit_paid?, recalculate?
     */
    public function execute(array $data, ?VillaBooking $booking = null): VillaBooking
    {
        $recalculate = (bool) ($data['recalculate'] ?? false);
        $override = array_key_exists('total_amount', $data) ? $data['total_amount'] : null;
        unset($data['recalculate'], $data['total_amount'], $data['auto_calculated_amount'], $data['portal_token']);

        $booking ??= new VillaBooking(['portal_token' => Villa::newPortalToken(), 'status' => VillaBookingStatus::Upcoming]);
        $isNew = ! $booking->exists;
        $booking->fill($data);

        $this->guardOverlap($booking);

        if ($isNew || $recalculate) {
            $villa = Villa::query()->findOrFail($booking->villa_id);
            $quote = $this->pricing->price($villa, $booking->check_in->toDateString(), $booking->check_out->toDateString(), (string) $booking->rebate_percent);
            // Plain minor units; the money cast gives them the tenant's currency.
            $booking->setAttribute('auto_calculated_amount', $quote['total']);
            $booking->setAttribute('total_amount', $override !== null ? (int) $override : $quote['total']);
        } elseif ($override !== null) {
            $booking->setAttribute('total_amount', (int) $override);
        }

        $booking->save();

        return $booking;
    }

    private function guardOverlap(VillaBooking $booking): void
    {
        $clash = VillaBooking::query()
            ->where('villa_id', $booking->villa_id)
            ->where('status', '!=', VillaBookingStatus::Cancelled)
            ->when($booking->exists, fn ($q) => $q->whereKeyNot($booking->getKey()))
            ->where('check_in', '<', $booking->check_out->toDateString())
            ->where('check_out', '>', $booking->check_in->toDateString())
            ->first();

        if ($clash instanceof VillaBooking && $booking->status !== VillaBookingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'check_in' => __('The villa is already booked by :guest from :from to :to.', [
                    'guest' => $clash->guest_name,
                    'from' => $clash->check_in->toDateString(),
                    'to' => $clash->check_out->toDateString(),
                ]),
            ]);
        }
    }
}
