<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderStatus;

/**
 * Which of an order's three stock modes may be combined — none of them. Each says something different
 * about where the goods are, so two together contradict each other:
 *
 *  - consignment: the goods are placed with the customer now, and stay ours until they sell them;
 *  - backorder:   the goods do not exist yet, so nothing is placed and nothing is deducted;
 *  - allocation:  the goods are in our cellar and set aside for the customer, deducted when shipped.
 *
 * One place for the rule so the form request, the order action (and so the API, AI import and the
 * scenario runner) cannot disagree about it.
 */
final class OrderModeGuard
{
    /**
     * @return array<string, string> the offending field => what is wrong with it; empty when the order is fine
     */
    public static function violations(bool $backorder, bool $consignment, bool $allocation, ?OrderStatus $status = null, ?bool $deductStock = null): array
    {
        $problems = [];

        if ($backorder && $consignment) {
            $problems['is_consignment'] = __('A consignment places goods with the customer now, but a backorder has none to place yet. Choose one.');
        }

        if ($allocation && $backorder) {
            $problems['is_allocation'] = __('An allocation sets aside stock you have; a backorder is stock you do not have yet. Choose one.');
        } elseif ($allocation && $consignment) {
            $problems['is_allocation'] = __('An allocation keeps the goods in your cellar; consignment places them with the customer. Choose one.');
        } elseif ($allocation && $deductStock === true) {
            $problems['is_allocation'] = __('An allocation does not deduct stock until the order ships.');
        }

        if ($allocation && $status === OrderStatus::Shipped) {
            $problems['status'] = __('An order that is already shipped cannot hold an allocation.');
        }

        return $problems;
    }
}
