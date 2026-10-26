<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\ReservationStatus;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;

/**
 * The old app's "Agencies" module: programs, the agency price book, reservations and their status
 * history (docs/21 §2.6).
 *
 * Prices and totals are copied verbatim — a reservation is never re-priced on import, because the
 * stored figure is what the group was actually charged. Rows whose program (or, for a price, whose
 * customer) did not come across are skipped and reported; a reservation whose agency did not come
 * across is kept as a walk-in and reported, so no booking or its money disappears silently.
 *
 * Optional legacy columns are read with `?? null`, so a column the old schema does not have reads as
 * empty instead of failing the run.
 */
class HospitalityStep extends AbstractStep
{
    public function name(): string
    {
        return 'hospitality';
    }

    public function dependsOn(): array
    {
        return ['users', 'customers'];
    }

    public function run(ImportContext $ctx): void
    {
        $this->programs($ctx);
        $this->agencyPrices($ctx);
        $this->reservations($ctx);
        $this->histories($ctx);
    }

    private function programs(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'HospitalityProgram') as $r) {
            $ctx->report->read('hospitality_programs');
            $duration = $r->durationMins ?? $r->duration ?? null;

            $this->put($ctx, 'HospitalityProgram', (string) $r->id, 'hospitality_programs', [
                'name' => $this->fit($ctx, 'hospitality_programs', 'program name', trim((string) $r->name)) ?? '',
                'description' => Normalize::str($r->description ?? null),
                'duration_mins' => $duration === null || $duration === '' ? null : (int) $duration,
                'base_price' => Normalize::minor($r->basePrice ?? null),
                'max_pax' => ($r->maxPax ?? null) === null ? null : (int) $r->maxPax,
                'is_active' => Normalize::bool($r->isActive ?? true),
                'sort_order' => (int) ($r->sortOrder ?? 0),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('hospitality_programs');
        }
    }

    private function agencyPrices(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'HospitalityAgencyPrice') as $r) {
            $ctx->report->read('hospitality_agency_prices');
            $program = $ctx->ids->get('HospitalityProgram', (string) $r->programId);
            $customer = $ctx->ids->get('Customer', (string) $r->customerId);

            if ($program === null || $customer === null) {
                $ctx->report->skipped('hospitality_agency_prices', "agency price {$r->id}: ".($program === null ? 'program' : 'customer').' not migrated');

                continue;
            }

            $rebate = Normalize::dec($r->rebatePercent ?? null, 2);

            $this->put($ctx, 'HospitalityAgencyPrice', (string) $r->id, 'hospitality_agency_prices', [
                'program_id' => $program,
                'customer_id' => $customer,
                'price_per_pax' => Normalize::minor($r->pricePerPax ?? null),
                'rebate_percent' => $rebate !== null && (float) $rebate > 0 ? $rebate : null,
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('hospitality_agency_prices');
        }
    }

    private function reservations(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'Reservation') as $r) {
            $ctx->report->read('reservations');
            $program = $ctx->ids->get('HospitalityProgram', (string) $r->programId);

            if ($program === null) {
                $ctx->report->skipped('reservations', "reservation {$r->id}: program not migrated");

                continue;
            }

            $legacyCustomer = Normalize::str($r->customerId ?? null);
            $customer = $legacyCustomer === null ? null : $ctx->ids->get('Customer', $legacyCustomer);
            if ($legacyCustomer !== null && $customer === null) {
                $ctx->report->warn('reservations', "reservation {$r->id}: customer {$legacyCustomer} not migrated; kept as a walk-in");
            }

            $this->put($ctx, 'Reservation', (string) $r->id, 'reservations', [
                'date' => Normalize::date($r->date),
                'pax' => (int) $r->pax,
                'status' => $this->status($ctx, (string) $r->id, $r->status ?? null),
                'price_per_pax' => Normalize::minor($r->pricePerPax ?? null),
                'total_amount' => Normalize::minor($r->totalAmount ?? null),
                'guest_name' => $this->fit($ctx, 'reservations', 'guest name', Normalize::str($r->guestName ?? null)),
                'guest_email' => $this->fit($ctx, 'reservations', 'guest email', Normalize::str($r->guestEmail ?? null)),
                'guest_phone' => $this->fit($ctx, 'reservations', 'guest phone', Normalize::str($r->guestPhone ?? null)),
                'allergies' => Normalize::str($r->allergies ?? null),
                'notes' => Normalize::str($r->notes ?? null),
                'cancel_reason' => Normalize::str($r->cancelReason ?? null),
                'program_id' => $program,
                'customer_id' => $customer,
                'created_by_id' => $ctx->userId(Normalize::str($r->createdById ?? null), 'reservations'),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('reservations');
        }
    }

    private function histories(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'ReservationStatusHistory') as $r) {
            $ctx->report->read('reservation_status_histories');
            $reservation = $ctx->ids->get('Reservation', (string) $r->reservationId);

            if ($reservation === null) {
                $ctx->report->skipped('reservation_status_histories', "history {$r->id}: reservation not migrated");

                continue;
            }

            $this->put($ctx, 'ReservationStatusHistory', (string) $r->id, 'reservation_status_histories', [
                'reservation_id' => $reservation,
                'status' => $this->status($ctx, "history {$r->id}", $r->status ?? null),
                'note' => Normalize::str($r->note ?? null),
                'changed_by_id' => $ctx->userId(Normalize::str($r->createdById ?? $r->changedById ?? null), 'reservations'),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
            ]);
            $ctx->report->written('reservation_status_histories');
        }
    }

    /** The legacy status as stored, or PENDING (reported) for a value the rebuild does not know. */
    private function status(ImportContext $ctx, string $what, mixed $value): string
    {
        $status = ReservationStatus::tryFrom(strtoupper(trim((string) $value)));

        if ($status === null) {
            $ctx->report->warn('reservations', "{$what}: unknown status '".(string) $value."'; imported as PENDING");

            return ReservationStatus::Pending->value;
        }

        return $status->value;
    }
}
