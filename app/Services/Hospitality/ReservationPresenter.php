<?php

declare(strict_types=1);

namespace App\Services\Hospitality;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\HospitalityProgram;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Shapes reservations and programs for the hospitality pages.
 *
 * A reservation's date is the winery's wall-clock time and goes out as `Y-m-d H:i` with no zone,
 * so the browser shows exactly what was booked instead of shifting it to the viewer's zone.
 */
class ReservationPresenter
{
    public function __construct(private readonly ReservationPricing $pricing) {}

    /**
     * @param  LengthAwarePaginator<int, Reservation>  $paginator
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function page(LengthAwarePaginator $paginator): array
    {
        /** @var list<Reservation> $reservations */
        $reservations = $paginator->items();

        return [
            'data' => array_map(fn (Reservation $reservation): array => $this->row($reservation), $reservations),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Reservation $reservation): array
    {
        return [
            'id' => $reservation->getKey(),
            'date' => $reservation->date->format('Y-m-d H:i'),
            'pax' => $reservation->pax,
            'status' => $reservation->status->value,
            'price_per_pax' => $reservation->price_per_pax->jsonSerialize(),
            'total_amount' => $reservation->total_amount->jsonSerialize(),
            'guest_name' => $reservation->guest_name,
            'program' => $reservation->program instanceof HospitalityProgram
                ? ['id' => $reservation->program->getKey(), 'name' => $reservation->program->name]
                : null,
            'customer' => $reservation->customer instanceof Customer
                ? ['id' => $reservation->customer->getKey(), 'name' => $reservation->customer->company_name]
                : null,
            'created_by' => $reservation->createdBy?->fullName(),
        ];
    }

    /**
     * The detail page: everything on the row plus the guest's contact details, the profitability
     * card (what the group would have paid at list price against what it pays) and the timeline.
     *
     * @return array<string, mixed>
     */
    public function detail(Reservation $reservation): array
    {
        $reservation->loadMissing(['program', 'customer', 'createdBy', 'statusHistories.changedBy']);

        $program = $reservation->program;
        $base = $program instanceof HospitalityProgram ? $program->base_price : null;
        $listTotal = $base !== null ? $this->pricing->total($base->getMinorAmount(), $reservation->pax) : null;
        $price = $reservation->price_per_pax->getMinorAmount();

        return [
            ...$this->row($reservation),
            'guest_email' => $reservation->guest_email,
            'guest_phone' => $reservation->guest_phone,
            'allergies' => $reservation->allergies,
            'notes' => $reservation->notes,
            'cancel_reason' => $reservation->cancel_reason,
            'program' => $program instanceof HospitalityProgram ? $this->program($program) : null,
            'customer' => $reservation->customer instanceof Customer
                ? [
                    'id' => $reservation->customer->getKey(),
                    'name' => $reservation->customer->company_name,
                    'is_agency' => (bool) $reservation->customer->is_agency,
                ]
                : null,
            'profitability' => [
                'list_total' => $base !== null && $listTotal !== null
                    ? ['minor' => $listTotal, 'currency' => $base->getCurrencyCode()]
                    : null,
                // The discount the group got off the program's list price, as a percentage.
                'rebate_percent' => $base !== null && $base->getMinorAmount() > 0
                    ? round((1 - $price / $base->getMinorAmount()) * 100, 1)
                    : null,
            ],
            'next_status' => $reservation->status->next()?->value,
            'deletable' => $reservation->status->isDeletable(),
            'history' => $reservation->statusHistories
                ->map(fn (ReservationStatusHistory $line): array => [
                    'id' => $line->getKey(),
                    'status' => $line->status->value,
                    'note' => $line->note,
                    'by' => $line->changedBy?->fullName(),
                    'at' => $line->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function program(HospitalityProgram $program): array
    {
        return [
            'id' => $program->getKey(),
            'name' => $program->name,
            'description' => $program->description,
            'duration_mins' => $program->duration_mins,
            'base_price' => $program->base_price->jsonSerialize(),
            'max_pax' => $program->max_pax,
            'is_active' => $program->is_active,
        ];
    }

    /**
     * The status set in flow order, with labels, for chips, selects and badges.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function statuses(): array
    {
        return array_map(
            fn (ReservationStatus $status): array => ['value' => $status->value, 'label' => __($status->label())],
            [...ReservationStatus::flow(), ReservationStatus::Cancelled],
        );
    }
}
