<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\InflowStatus;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Mapping;
use App\Services\LegacyImport\Support\Normalize;
use stdClass;

/**
 * Inflow → inflows (money in / receivables).
 *
 * The rebuild has no "cancelled" state, and a cancelled legacy invoice stays PENDING, so it
 * would be counted as a receivable. Legacy cancellations are always an invoice plus an equal
 * credit note that reverses it (net zero), so both halves of each pair are skipped and listed.
 */
class InflowsStep extends AbstractStep
{
    public function name(): string
    {
        return 'inflows';
    }

    public function dependsOn(): array
    {
        return ['users', 'customers', 'orders'];
    }

    public function run(ImportContext $ctx): void
    {
        $reversals = $ctx->legacy->table('Inflow')->whereNotNull('cancelledByInflowId')->pluck('cancelledByInflowId')->flip();
        $payments = 0;

        foreach ($this->rows($ctx, 'Inflow') as $r) {
            $ctx->report->read('inflows');

            if (Normalize::bool($r->isCancelled) || isset($reversals[(string) $r->id])) {
                $ctx->report->skipped('inflows', 'cancelled pair half not migrated: '.($r->reference ?? $r->id).(Normalize::bool($r->isCreditNote) ? ' (reversing credit note)' : ' (cancelled invoice)'));

                continue;
            }

            $status = InflowStatus::tryFrom((string) $r->status);
            if ($status === null) {
                $ctx->report->skipped('inflows', "inflow {$r->id} ({$r->reference}): unknown status '{$r->status}'");

                continue;
            }

            $customer = $r->customerId === null ? null : $ctx->ids->get('Customer', (string) $r->customerId);
            $order = $r->orderId === null ? null : $ctx->ids->get('Order', (string) $r->orderId);
            $payments += strtoupper((string) $r->type) === 'PAYMENT' ? 1 : 0;

            $this->put($ctx, 'Inflow', (string) $r->id, 'inflows', [
                'customer_id' => $customer,
                'order_id' => $order,
                'date' => Normalize::date($r->date),
                'amount' => Normalize::minor($r->totalAmount),
                'vat_amount' => Normalize::minorOrNull($r->vatAmount),
                'status' => $status->value,
                'is_credit_note' => Normalize::bool($r->isCreditNote),
                // The old summaries counted "invoiced" money in as: e-invoice linked or type INVOICE, never credit notes.
                'is_invoice' => ! Normalize::bool($r->isCreditNote) && ($r->eInvoiceId !== null || strtoupper((string) $r->type) === 'INVOICE'),
                'category' => $this->fit($ctx, 'inflows', "inflow {$r->id} category", Normalize::str($r->category)),
                'reference' => $this->fit($ctx, 'inflows', "inflow {$r->id} reference", Normalize::str($r->reference)),
                'payment_method' => Mapping::paymentMethod($r->paymentMethod),
                // No description column in the rebuild; keep it in the notes rather than lose it.
                'notes' => $this->notes($r),
                'due_date' => Normalize::date($r->dueDate),
                'received_at' => Normalize::date($r->receivedAt),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'inflows'),
                'is_ai_generated' => false,
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('inflows');
        }

        if ($payments > 0) {
            $ctx->report->warn('inflows', "{$payments} legacy PAYMENT-type inflows imported as ordinary received inflows (bank-statement receipts such as card settlements; the INVOICE/PAYMENT type has no counterpart)");
        }
    }

    private function notes(stdClass $r): ?string
    {
        $parts = array_filter([Normalize::str($r->description), Normalize::str($r->notes)]);

        return $parts === [] ? null : implode("\n", array_unique($parts));
    }
}
