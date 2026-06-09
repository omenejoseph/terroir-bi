<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CostStatus;
use App\Models\Cost;
use App\Models\Order;
use App\Models\Supplier;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Expense analytics for a period: total spend, unpaid, by status/category/
 * supplier, monthly spend, and a profit-and-loss (order revenue vs costs by
 * month). Monthly bucketing is done in PHP to stay database-agnostic.
 */
class CostAnalyticsQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Carbon $from, Carbon $to): array
    {
        $currency = $this->currency();

        $costs = Cost::query()->whereBetween('date', [$from, $to])
            ->get(['date', 'total_amount', 'category', 'status', 'supplier_id']);

        $total = $costs->sum(fn (Cost $c) => $c->total_amount->getMinorAmount());
        $unpaid = $costs->where('status', '!=', CostStatus::Paid)
            ->sum(fn (Cost $c) => $c->total_amount->getMinorAmount());

        $orders = Order::query()->where('is_consignment', false)
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at', 'total_amount']);

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'total_spend' => Money::fromMinor((int) $total, $currency)->jsonSerialize(),
            'unpaid' => Money::fromMinor((int) $unpaid, $currency)->jsonSerialize(),
            'by_status' => $this->byStatus($costs, $currency),
            'by_category' => $this->grouped($costs, fn (Cost $c) => $c->category, $currency),
            'by_supplier' => $this->bySupplier($costs, $currency),
            'over_time' => $this->monthly(array_values($costs->map(fn (Cost $c) => [$c->date, $c->total_amount->getMinorAmount()])->all()), $currency),
            'profit_loss' => $this->profitLoss($costs, $orders, $currency),
        ];
    }

    /**
     * @param  Collection<int, Cost>  $costs
     * @return list<array<string, mixed>>
     */
    private function byStatus($costs, string $currency): array
    {
        return array_values($costs->groupBy(fn (Cost $c) => $c->status->value)
            ->map(fn ($group, $status) => [
                'status' => $status,
                'count' => $group->count(),
                'total' => Money::fromMinor((int) $group->sum(fn (Cost $c) => $c->total_amount->getMinorAmount()), $currency)->jsonSerialize(),
            ])->values()->all());
    }

    /**
     * @param  Collection<int, Cost>  $costs
     * @param  callable(Cost): string  $key
     * @return list<array<string, mixed>>
     */
    private function grouped($costs, callable $key, string $currency): array
    {
        return array_values($costs->groupBy($key)
            ->map(fn ($group, $name) => [
                'name' => (string) $name,
                'total' => Money::fromMinor((int) $group->sum(fn (Cost $c) => $c->total_amount->getMinorAmount()), $currency)->jsonSerialize(),
            ])->sortByDesc(fn (array $r) => $r['total']['minor'])->values()->all());
    }

    /**
     * @param  Collection<int, Cost>  $costs
     * @return list<array<string, mixed>>
     */
    private function bySupplier($costs, string $currency): array
    {
        $byId = $costs->whereNotNull('supplier_id')->groupBy('supplier_id');
        $names = Supplier::query()->whereIn('id', $byId->keys())->pluck('company_name', 'id');

        return array_values($byId->map(fn ($group, $id) => [
            'supplier_id' => (string) $id,
            'company_name' => $names[$id] ?? null,
            'total' => Money::fromMinor((int) $group->sum(fn (Cost $c) => $c->total_amount->getMinorAmount()), $currency)->jsonSerialize(),
        ])->sortByDesc(fn (array $r) => $r['total']['minor'])->values()->all());
    }

    /**
     * @param  list<array{0: Carbon, 1: int}>  $rows
     * @return list<array{month: string, total: array<string, mixed>}>
     */
    private function monthly(array $rows, string $currency): array
    {
        $byMonth = [];
        foreach ($rows as [$date, $minor]) {
            $month = $date->format('Y-m');
            $byMonth[$month] = ($byMonth[$month] ?? 0) + $minor;
        }
        ksort($byMonth);

        $out = [];
        foreach ($byMonth as $month => $minor) {
            $out[] = ['month' => $month, 'total' => Money::fromMinor($minor, $currency)->jsonSerialize()];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Cost>  $costs
     * @param  Collection<int, Order>  $orders
     * @return list<array<string, mixed>>
     */
    private function profitLoss($costs, $orders, string $currency): array
    {
        $months = [];
        foreach ($costs as $c) {
            $months[$c->date->format('Y-m')]['costs'] = ($months[$c->date->format('Y-m')]['costs'] ?? 0) + $c->total_amount->getMinorAmount();
        }
        foreach ($orders as $o) {
            $m = ($o->created_at ?? Carbon::now())->format('Y-m');
            $months[$m]['revenue'] = ($months[$m]['revenue'] ?? 0) + $o->total_amount->getMinorAmount();
        }
        ksort($months);

        $out = [];
        foreach ($months as $month => $vals) {
            $revenue = (int) ($vals['revenue'] ?? 0);
            $cost = (int) ($vals['costs'] ?? 0);
            $out[] = [
                'month' => $month,
                'revenue' => Money::fromMinor($revenue, $currency)->jsonSerialize(),
                'costs' => Money::fromMinor($cost, $currency)->jsonSerialize(),
                'profit' => Money::fromMinor($revenue - $cost, $currency)->jsonSerialize(),
            ];
        }

        return $out;
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
