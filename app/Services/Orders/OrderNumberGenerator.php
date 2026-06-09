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
        $last = Order::query()->orderByDesc('order_number')->value('order_number');

        $lastNumber = is_string($last) ? (int) preg_replace('/\D/', '', $last) : 0;

        return self::PREFIX.str_pad((string) ($lastNumber + 1), 5, '0', STR_PAD_LEFT);
    }
}
