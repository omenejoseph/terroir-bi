<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Finance\CreateInflowAction;
use App\Enums\InflowStatus;
use App\Models\Inflow;
use App\Models\Order;
use App\Services\Finance\OrderPaymentSummary;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * The drawer's overflow menu "Mark paid" — records a RECEIVED Inflow for
 * whatever is still outstanding, rather than a boolean flag on the order:
 * payment state is already entirely derived from Inflow rows
 * (App\Services\Finance\OrderPaymentSummary), so this is the one honest way
 * to move an order to PAID without a second, competing source of truth.
 */
class MarkOrderPaidAction
{
    public function __construct(
        private readonly CreateInflowAction $createInflow,
        private readonly OrderPaymentSummary $payments,
    ) {}

    public function execute(Order $order, string $createdById): Inflow
    {
        $summary = $this->payments->for($order);
        $balanceDue = (int) $summary['balance_due']['minor'];

        if ($balanceDue <= 0) {
            throw ValidationException::withMessages([
                'order' => __('This order is already fully paid.'),
            ]);
        }

        return $this->createInflow->execute([
            'customer_id' => $order->customer_id,
            'order_id' => $order->getKey(),
            'amount' => Money::fromMinor($balanceDue, $summary['balance_due']['currency']),
            'status' => InflowStatus::Received,
            'category' => 'Order payment',
            'reference' => $order->order_number,
        ], $createdById);
    }
}
