<?php

declare(strict_types=1);

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case InvoiceSent = 'INVOICE_SENT';
    case Paid = 'PAID';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::InvoiceSent => 'Invoice Sent',
            self::Paid => 'Paid',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The happy path, in order. The old app's status card walks this list one step at a time;
     * Cancelled sits outside it and can be chosen from any other status.
     *
     * @return list<self>
     */
    public static function flow(): array
    {
        return [self::Pending, self::Confirmed, self::InvoiceSent, self::Paid, self::Completed];
    }

    /** The next step on the flow, or null once Completed (and for a Cancelled reservation). */
    public function next(): ?self
    {
        $flow = self::flow();
        $index = array_search($this, $flow, true);

        return $index === false ? null : ($flow[$index + 1] ?? null);
    }

    /** Only these can be deleted outright; anything further along is history. */
    public function isDeletable(): bool
    {
        return $this === self::Pending || $this === self::Cancelled;
    }

    /** Money that has actually come in — the only statuses the dashboard counts as revenue. */
    public function isRealized(): bool
    {
        return $this === self::Paid || $this === self::Completed;
    }

    /** Confirmed-or-beyond, for the conversion rate (cancelled bookings stay in the denominator). */
    public function isConverted(): bool
    {
        return in_array($this, [self::Confirmed, self::InvoiceSent, self::Paid, self::Completed], true);
    }

    /** Still ahead of us and still owed: the revenue pipeline. */
    public function isOpenPipeline(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::InvoiceSent, self::Paid], true);
    }
}
