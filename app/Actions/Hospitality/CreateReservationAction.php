<?php

declare(strict_types=1);

namespace App\Actions\Hospitality;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\HospitalityProgram;
use App\Models\Reservation;
use App\Services\Hospitality\ReservationPricing;
use Illuminate\Support\Facades\DB;

class CreateReservationAction
{
    public function __construct(private readonly ReservationPricing $pricing) {}

    /**
     * @param  array{date: string, pax: int, program_id: string, customer_id?: ?string, guest_name?: ?string, guest_email?: ?string, guest_phone?: ?string, allergies?: ?string, notes?: ?string}  $data
     */
    public function execute(array $data, string $createdById): Reservation
    {
        $program = HospitalityProgram::query()->findOrFail($data['program_id']);
        $customer = ($data['customer_id'] ?? null) ? Customer::query()->findOrFail($data['customer_id']) : null;

        $pricePerPax = $this->pricing->pricePerPax($program, $customer);

        return DB::transaction(function () use ($data, $program, $customer, $pricePerPax, $createdById): Reservation {
            $reservation = Reservation::create([
                ...$data,
                'program_id' => $program->getKey(),
                'customer_id' => $customer?->getKey(),
                'status' => ReservationStatus::Pending,
                'price_per_pax' => $pricePerPax,
                'total_amount' => $this->pricing->total($pricePerPax, (int) $data['pax']),
                'created_by_id' => $createdById,
            ]);

            $reservation->statusHistories()->create([
                'status' => ReservationStatus::Pending,
                'note' => __('Reservation created'),
                'changed_by_id' => $createdById,
            ]);

            return $reservation;
        });
    }
}
