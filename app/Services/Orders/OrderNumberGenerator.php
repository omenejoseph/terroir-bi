<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;

/**
 * Generates the next tenant-scoped order number (ORD-NNNNN). The Order query is
 * tenant-scoped, so numbering restarts per tenant. Call inside the order
 * creation transaction; a unique (tenant_id, order_number) index is the
 * backstop against a race.
 */
class OrderNumberGenerator
{
    private const PREFIX = 'ORD-';

    public function next(): string
    {
        // Sorting order_number as a STRING only stays safe among rows this
        // generator itself wrote — always this prefix, always 5-digit
        // zero-padded, so string order and numeric order agree. It breaks
        // the moment a DIFFERENTLY-shaped row sorts in: DemoSeeder's
        // "TREND-NNN" trend-history rows sort after every real "ORD-NNNNN"
        // row purely because 'T' > 'O', so the "last" row was always a
        // TREND- one and its unrelated digits became the next ORD- number —
        // recomputing the same already-taken number on every attempt, so
        // even CreateOrderAction's retry-on-collision couldn't get past it.
        // Filtering to this prefix is enough to fix it, with no need for a
        // numeric CAST that isn't spelled the same way on every DB driver.
        $last = Order::query()
            ->where('order_number', 'like', self::PREFIX.'%')
            ->orderByDesc('order_number')
            ->value('order_number');

        $lastNumber = is_string($last) ? (int) preg_replace('/\D/', '', $last) : 0;

        return self::PREFIX.str_pad((string) ($lastNumber + 1), 5, '0', STR_PAD_LEFT);
    }
}
