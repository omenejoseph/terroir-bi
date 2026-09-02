<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * Counts for the status chip row above the orders table (Figma 455:1577:
 * "All 37 · Received 2 · In Process 4 · Ready to Ship 6 · Shipped 25").
 *
 * The chips double as filters, so their counts deliberately ignore the status
 * filter — otherwise selecting "Shipped" would zero every other chip and you
 * could not see what you were switching to. Every other filter (search,
 * customer, period, the shipped-visibility rule) does apply, so the numbers
 * describe the set you are actually looking through.
 */
class OrderStatusCountsQuery
{
    public function __construct(private readonly ListOrdersQuery $orders) {}

    /**
     * @param  array{status?: ?string, search?: ?string, hide_shipped?: ?bool, customer_id?: ?string, from?: ?string, to?: ?string}  $filters
     * @return array{total: int, statuses: list<array{key: string, label: string, count: int}>}
     */
    public function get(array $filters): array
    {
        unset($filters['status']);

        $counts = $this->orders->build($filters)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as orders')
            ->get()
            ->mapWithKeys(fn (Order $row): array => [
                (string) $row->getRawOriginal('status') => (int) $row->getAttribute('orders'),
            ]);

        $statuses = array_map(static fn (OrderStatus $status): array => [
            'key' => $status->value,
            'label' => $status->label(),
            'count' => $counts[$status->value] ?? 0,
        ], OrderStatus::cases());

        return [
            'total' => array_sum(array_column($statuses, 'count')),
            'statuses' => $statuses,
        ];
    }
}
