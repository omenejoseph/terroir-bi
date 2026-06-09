<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Authorization\MembershipContext;
use App\Models\Order;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Enforces the order line-edit window: ADMINs and members with can_edit_orders
 * may edit at any time; everyone else only within an hour of order creation.
 * Cost/shipping edits are exempt and must not call this.
 */
class OrderEditGuard
{
    private const WINDOW_MINUTES = 60;

    public function __construct(private readonly MembershipContext $membership) {}

    public function ensureEditable(Order $order): void
    {
        if ($this->membership->canEditOrders()) {
            return;
        }

        $createdAt = $order->created_at;

        if ($createdAt !== null && $createdAt->diffInMinutes(now()) <= self::WINDOW_MINUTES) {
            return;
        }

        throw new HttpException(403, 'This order can no longer be edited (the 1-hour window has passed).');
    }
}
