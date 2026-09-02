<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InflowStatus;
use App\Enums\OrderStatus;
use App\Models\Inflow;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;

/**
 * The "Order-to-cash pipeline" card on the Orders list (Figma 455:1577): how
 * many orders sit at each stage in the period, what they are worth, and each
 * stage's share of the largest one (the bar).
 *
 * A note on the stage names. The design labels six stages Draft / Confirmed /
 * Picking-packed / Delivered / Invoiced / Paid. Four of those are a fulfilment
 * vocabulary this domain does not use — `OrderStatus` is Received / In Process /
 * Ready to Ship / Shipped — and relabelling the real statuses to match would
 * report something the data does not mean. So the card keeps the design's shape
 * (six stages, count, value, bar) with this system's own vocabulary: the four
 * order statuses, then Invoiced and Paid derived from the inflows an order has
 * received. The divergence is logged in docs/design/README.md.
 *
 * Invoiced and Paid overlap the fulfilment stages by construction — an order
 * can be Shipped *and* Paid. That is a property of order-to-cash, not a bug:
 * the first four stages say where the goods are, the last two where the money is.
 */
class OrderPipelineQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{stages: list<array{key: string, label: string, count: int, value: array<string, mixed>, share: float}>, currency: string}
     */
    public function get(Carbon $from, Carbon $to): array
    {
        $currency = $this->currency();

        $stages = [];

        foreach ($this->byStatus($from, $to) as $status) {
            $stages[] = $status;
        }

        $stages[] = $this->invoiced($from, $to);
        $stages[] = $this->paid($from, $to);

        $largest = max(1, ...array_map(static fn (array $s): int => $s['minor'], $stages));

        return [
            'currency' => $currency,
            'stages' => array_map(
                static fn (array $stage): array => [
                    'key' => $stage['key'],
                    'label' => $stage['label'],
                    'count' => $stage['count'],
                    'value' => Money::fromMinor($stage['minor'], $currency)->jsonSerialize(),
                    'share' => round($stage['minor'] / $largest, 4),
                ],
                $stages,
            ),
        ];
    }

    /**
     * The four fulfilment stages. One grouped query, then padded so a status
     * with no orders still gets a column — an empty stage is information.
     *
     * @return list<array{key: string, label: string, count: int, minor: int}>
     */
    private function byStatus(Carbon $from, Carbon $to): array
    {
        $rows = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as value')
            ->get()
            ->keyBy(fn (Order $row): string => (string) $row->getRawOriginal('status'));

        return array_map(static function (OrderStatus $status) use ($rows): array {
            $row = $rows->get($status->value);

            return [
                'key' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($row?->getAttribute('orders') ?? 0),
                'minor' => (int) ($row?->getAttribute('value') ?? 0),
            ];
        }, OrderStatus::cases());
    }

    /**
     * Invoiced: orders that have at least one inflow raised against them,
     * whether or not it has been received.
     *
     * @return array{key: string, label: string, count: int, minor: int}
     */
    private function invoiced(Carbon $from, Carbon $to): array
    {
        $orders = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('inflows')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as value')
            ->first();

        return [
            'key' => 'INVOICED',
            'label' => 'Invoiced',
            'count' => (int) ($orders?->getAttribute('orders') ?? 0),
            'minor' => (int) ($orders?->getAttribute('value') ?? 0),
        ];
    }

    /**
     * Paid: orders whose received inflows (credit notes counting negatively)
     * cover the order total. Same arithmetic as OrderPaymentSummary, expressed
     * set-wise so the card is one query rather than one per order.
     *
     * @return array{key: string, label: string, count: int, minor: int}
     */
    private function paid(Carbon $from, Carbon $to): array
    {
        $received = Inflow::query()
            ->select('order_id')
            ->whereNotNull('order_id')
            ->where('status', InflowStatus::Received->value)
            ->groupBy('order_id')
            ->selectRaw('SUM(CASE WHEN is_credit_note THEN -amount ELSE amount END) as paid');

        $row = Order::query()
            ->whereBetween('orders.created_at', [$from, $to])
            ->joinSub($received, 'settled', 'settled.order_id', '=', 'orders.id')
            ->whereColumn('settled.paid', '>=', 'orders.total_amount')
            ->where('orders.total_amount', '>', 0)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(orders.total_amount), 0) as value')
            ->first();

        return [
            'key' => 'PAID',
            'label' => 'Paid',
            'count' => (int) ($row?->getAttribute('orders') ?? 0),
            'minor' => (int) ($row?->getAttribute('value') ?? 0),
        ];
    }

    private function currency(): string
    {
        return $this->tenant->current()?->settings()->first()?->default_currency
            ?? CurrencyRegistry::default()->code;
    }
}
