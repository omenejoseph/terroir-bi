<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\InflowStatus;
use App\Models\Inflow;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Derives an order's payment state from its received inflows (credit notes
 * counting negatively): how much is paid, the outstanding balance, and a
 * UNPAID / PARTIAL / PAID status.
 */
class OrderPaymentSummary
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Order $order): array
    {
        $paid = (int) Inflow::query()
            ->where('order_id', $order->getKey())
            ->where('status', InflowStatus::Received->value)
            ->sum(DB::raw('CASE WHEN is_credit_note THEN -amount ELSE amount END'));

        $total = $order->total_amount->getMinorAmount();
        $balance = $total - $paid;
        $currency = $this->currency();

        return [
            'amount_paid' => Money::fromMinor($paid, $currency)->jsonSerialize(),
            'balance_due' => Money::fromMinor($balance, $currency)->jsonSerialize(),
            'status' => $this->status($paid, $total),
        ];
    }

    private function status(int $paid, int $total): string
    {
        return match (true) {
            $paid <= 0 => 'UNPAID',
            $paid >= $total => 'PAID',
            default => 'PARTIAL',
        };
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
