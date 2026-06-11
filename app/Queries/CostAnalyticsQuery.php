<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CostStatus;
use App\Models\Cost;
use App\Models\Inflow;
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
            ->get(['id', 'date', 'total_amount', 'category', 'status', 'supplier_id', 'paid_at']);

        $total = $costs->sum(fn (Cost $c) => $c->total_amount->getMinorAmount());
        $unpaid = $costs->where('status', '!=', CostStatus::Paid)
            ->sum(fn (Cost $c) => $c->total_amount->getMinorAmount());

        $orders = Order::query()->where('is_consignment', false)
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at', 'total_amount']);

        // Revenue (for gross margin) comes from inflows in the same period.
        $revenue = (int) Inflow::query()->whereBetween('date', [$from, $to])
            ->get(['amount'])->sum(fn (Inflow $i) => $i->amount->getMinorAmount());

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'total_spend' => Money::fromMinor((int) $total, $currency)->jsonSerialize(),
            'unpaid' => Money::fromMinor((int) $unpaid, $currency)->jsonSerialize(),
            'invoiced' => $this->invoiceCard($costs, null, $currency),
            'paid' => $this->invoiceCard($costs, CostStatus::Paid, $currency),
            'unpaid_invoices' => $this->invoiceCard($costs, 'unpaid', $currency),
            'avg_invoice' => $this->avgInvoice($costs, $currency),
            'avg_days_to_pay' => $this->avgDaysToPay($costs),
            'gross_margin' => [
                'percent' => $revenue > 0 ? (string) round(($revenue - (int) $total) / $revenue * 100, 1) : null,
                'revenue' => Money::fromMinor($revenue, $currency)->jsonSerialize(),
            ],
            'by_status' => $this->byStatus($costs, $currency),
            'by_category' => $this->grouped($costs, fn (Cost $c) => $c->category, $currency),
            'by_supplier' => $this->bySupplier($costs, $currency),
            'over_time' => $this->monthly(array_values($costs->map(fn (Cost $c) => [$c->date, $c->total_amount->getMinorAmount()])->all()), $currency),
            'yoy' => $this->yearOverYear($to->year, $currency),
            'top_costs' => $this->topCosts($costs, $currency),
            'profit_loss' => $this->profitLoss($costs, $orders, $currency),
        ];
    }

    /**
     * Invoiced / Paid / Unpaid card: total + count over the 'Invoice' category.
     *
     * @param  Collection<int, Cost>  $costs
     * @return array<string, mixed>
     */
    private function invoiceCard(Collection $costs, CostStatus|string|null $filter, string $currency): array
    {
        $invoices = $costs->where('category', ListCostsQuery::INVOICE_CATEGORY);
        $rows = match (true) {
            $filter instanceof CostStatus => $invoices->where('status', $filter),
            $filter === 'unpaid' => $invoices->where('status', '!=', CostStatus::Paid),
            default => $invoices,
        };

        return [
            'total' => Money::fromMinor((int) $rows->sum(fn (Cost $c) => $c->total_amount->getMinorAmount()), $currency)->jsonSerialize(),
            'count' => $rows->count(),
        ];
    }

    /**
     * Average + maximum invoice amount over the 'Invoice' category.
     *
     * @param  Collection<int, Cost>  $costs
     * @return array<string, mixed>
     */
    private function avgInvoice(Collection $costs, string $currency): array
    {
        $invoices = $costs->where('category', ListCostsQuery::INVOICE_CATEGORY);
        $count = $invoices->count();
        $sum = (int) $invoices->sum(fn (Cost $c) => $c->total_amount->getMinorAmount());
        $max = (int) ($invoices->max(fn (Cost $c) => $c->total_amount->getMinorAmount()) ?? 0);

        return [
            'avg' => Money::fromMinor($count > 0 ? intdiv($sum, $count) : 0, $currency)->jsonSerialize(),
            'max' => Money::fromMinor($max, $currency)->jsonSerialize(),
        ];
    }

    /**
     * Mean days from invoice date to paid date, over paid invoices.
     *
     * @param  Collection<int, Cost>  $costs
     * @return array<string, mixed>
     */
    private function avgDaysToPay(Collection $costs): array
    {
        $paid = $costs->where('category', ListCostsQuery::INVOICE_CATEGORY)
            ->where('status', CostStatus::Paid)
            ->filter(fn (Cost $c) => $c->paid_at !== null);
        $count = $paid->count();
        $days = (float) $paid->sum(fn (Cost $c) => (float) $c->date->diffInDays($c->paid_at));

        return ['days' => $count > 0 ? round($days / $count, 1) : null, 'count' => $count];
    }

    /**
     * @param  Collection<int, Cost>  $costs
     * @return list<array<string, mixed>>
     */
    private function topCosts(Collection $costs, string $currency): array
    {
        $names = Supplier::query()
            ->whereIn('id', $costs->pluck('supplier_id')->filter()->unique()->values())
            ->pluck('company_name', 'id');

        return array_values($costs
            ->sortByDesc(fn (Cost $c) => $c->total_amount->getMinorAmount())
            ->take(10)
            ->map(fn (Cost $c) => [
                'id' => $c->getKey(),
                'date' => $c->date->toIso8601String(),
                'category' => $c->category,
                'supplier_name' => $c->supplier_id !== null ? ($names[$c->supplier_id] ?? null) : null,
                'total' => $c->total_amount->jsonSerialize(),
            ])->values()->all());
    }

    /**
     * Monthly cost totals for the given year vs the prior year.
     *
     * @return array<string, mixed>
     */
    private function yearOverYear(int $year, string $currency): array
    {
        $rows = Cost::query()
            ->whereBetween('date', [
                Carbon::parse(sprintf('%d-01-01', $year - 1))->startOfDay(),
                Carbon::parse(sprintf('%d-12-31', $year))->endOfDay(),
            ])
            ->get(['date', 'total_amount']);

        $current = array_fill(1, 12, 0);
        $previous = array_fill(1, 12, 0);
        foreach ($rows as $c) {
            $m = (int) $c->date->format('n');
            $minor = $c->total_amount->getMinorAmount();
            if ($c->date->year === $year) {
                $current[$m] += $minor;
            } elseif ($c->date->year === $year - 1) {
                $previous[$m] += $minor;
            }
        }

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = [
                'month' => $m,
                'current' => Money::fromMinor($current[$m], $currency)->jsonSerialize(),
                'previous' => Money::fromMinor($previous[$m], $currency)->jsonSerialize(),
            ];
        }

        return ['current_year' => $year, 'previous_year' => $year - 1, 'months' => $months];
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
