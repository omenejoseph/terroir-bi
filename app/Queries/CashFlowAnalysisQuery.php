<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CostStatus;
use App\Enums\InflowStatus;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\Inflow;
use App\Models\Supplier;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cash Flow Analysis for a period — the money-movement counterpart to the cost
 * and inflow analytics. Cash in = received inflows, cash out = paid costs (both
 * keyed on their record date, matching the rest of the rebuild's cash-flow
 * model). Surfaces actual-movement KPIs (with period-over-period trends), the
 * over-time + cumulative series, category comparison, payment discipline, the
 * outstanding balances, and the top receivables / payables.
 */
class CashFlowAnalysisQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Carbon $from, Carbon $to): array
    {
        $currency = $this->currency();
        $excluded = Customer::statsExcludedIds();

        $inflows = $this->receivedInflows($from, $to, $excluded);
        $costs = $this->paidCosts($from, $to);

        $cashIn = (int) $inflows->sum(fn (Inflow $i) => $i->signedMinor());
        $cashOut = (int) $costs->sum(fn (Cost $c) => $c->total_amount->getMinorAmount());
        $net = $cashIn - $cashOut;

        // Preceding equal-length window for the trend badges.
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subSeconds((int) $from->diffInSeconds($to));
        $prevInflows = $this->receivedInflows($prevFrom, $prevTo, $excluded);
        $prevCosts = $this->paidCosts($prevFrom, $prevTo);
        $prevIn = (int) $prevInflows->sum(fn (Inflow $i) => $i->signedMinor());
        $prevOut = (int) $prevCosts->sum(fn (Cost $c) => $c->total_amount->getMinorAmount());
        $prevNet = $prevIn - $prevOut;

        $outRec = $this->outstandingReceivables($excluded);
        $outPay = $this->outstandingPayables();
        // Calendar-month span of the window (e.g. Jun 1 → Jun 30 = 1 month).
        $months = max(1, ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1);

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'cash_in' => $this->movementCard($cashIn, $inflows->count(), $prevIn, $currency),
            'cash_out' => $this->movementCard($cashOut, $costs->count(), $prevOut, $currency),
            'net' => [
                'total' => Money::fromMinor($net, $currency)->jsonSerialize(),
                'previous' => Money::fromMinor($prevNet, $currency)->jsonSerialize(),
                'change' => $this->change($net, $prevNet),
            ],
            'burn_rate_monthly' => Money::fromMinor((int) round($cashOut / $months), $currency)->jsonSerialize(),
            'outstanding_receivables' => [
                'total' => Money::fromMinor($outRec['total'], $currency)->jsonSerialize(),
                'count' => $outRec['count'],
            ],
            'outstanding_payables' => [
                'total' => Money::fromMinor($outPay['total'], $currency)->jsonSerialize(),
                'count' => $outPay['count'],
            ],
            'net_working_capital' => Money::fromMinor($outRec['total'] - $outPay['total'], $currency)->jsonSerialize(),
            'payment_discipline' => $this->paymentDiscipline($inflows, $costs),
            'over_time' => $this->overTime($inflows, $costs, $currency),
            'by_category' => $this->byCategory($inflows, $costs, $currency),
            'top_outflow_categories' => $this->topOutflowCategories($costs, $currency),
            'top_receivables' => $this->topReceivables($excluded, $currency),
            'top_payables' => $this->topPayables($currency),
        ];
    }

    /**
     * @param  list<string>  $excluded
     * @return Collection<int, Inflow>
     */
    private function receivedInflows(Carbon $from, Carbon $to, array $excluded): Collection
    {
        return Inflow::query()
            ->where('status', InflowStatus::Received)
            ->whereBetween('date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('customer_id')->orWhereNotIn('customer_id', $excluded))
            ->get(['date', 'amount', 'category', 'is_credit_note', 'received_at', 'due_date']);
    }

    /**
     * @return Collection<int, Cost>
     */
    private function paidCosts(Carbon $from, Carbon $to): Collection
    {
        return Cost::query()
            ->where('status', CostStatus::Paid)
            ->whereBetween('date', [$from, $to])
            ->get(['date', 'total_amount', 'category', 'paid_at', 'due_date']);
    }

    /**
     * @return array<string, mixed>
     */
    private function movementCard(int $total, int $count, int $previous, string $currency): array
    {
        return [
            'total' => Money::fromMinor($total, $currency)->jsonSerialize(),
            'count' => $count,
            'previous' => Money::fromMinor($previous, $currency)->jsonSerialize(),
            'change' => $this->change($total, $previous),
        ];
    }

    private function change(int $current, int $previous): ?float
    {
        return $previous !== 0 ? round(($current - $previous) / abs($previous) * 100, 1) : null;
    }

    /**
     * @param  list<string>  $excluded
     * @return array{total: int, count: int}
     */
    private function outstandingReceivables(array $excluded): array
    {
        $rows = Inflow::query()
            ->where('status', InflowStatus::Pending)
            ->where('is_credit_note', false)
            ->where(fn ($q) => $q->whereNull('customer_id')->orWhereNotIn('customer_id', $excluded))
            ->get(['amount']);

        return ['total' => (int) $rows->sum(fn (Inflow $i) => $i->amount->getMinorAmount()), 'count' => $rows->count()];
    }

    /**
     * @return array{total: int, count: int}
     */
    private function outstandingPayables(): array
    {
        $rows = Cost::query()->where('status', '!=', CostStatus::Paid)->get(['total_amount']);

        return ['total' => (int) $rows->sum(fn (Cost $c) => $c->total_amount->getMinorAmount()), 'count' => $rows->count()];
    }

    /**
     * Days-to-collect / days-to-pay and the on-time percentages, using the
     * actual received_at / paid_at timing against the record + due dates.
     *
     * @param  Collection<int, Inflow>  $inflows
     * @param  Collection<int, Cost>  $costs
     * @return array<string, mixed>
     */
    private function paymentDiscipline(Collection $inflows, Collection $costs): array
    {
        $collected = $inflows->filter(fn (Inflow $i) => $i->received_at !== null);
        $collectDays = $collected->map(fn (Inflow $i) => (int) round($i->date->diffInDays($i->received_at, false)));
        $collectDue = $collected->filter(fn (Inflow $i) => $i->due_date !== null);
        $collectOnTime = $collectDue->filter(fn (Inflow $i) => $i->received_at !== null && $i->due_date !== null && $i->received_at->lte($i->due_date))->count();

        $paid = $costs->filter(fn (Cost $c) => $c->paid_at !== null);
        $payDays = $paid->map(fn (Cost $c) => (int) round($c->date->diffInDays($c->paid_at, false)));
        $payDue = $paid->filter(fn (Cost $c) => $c->due_date !== null);
        $payOnTime = $payDue->filter(fn (Cost $c) => $c->paid_at !== null && $c->due_date !== null && $c->paid_at->lte($c->due_date))->count();

        return [
            'avg_days_to_collect' => $collectDays->count() > 0 ? (int) round($collectDays->sum() / $collectDays->count()) : null,
            'collected_on_time_percent' => $collectDue->count() > 0 ? (int) round($collectOnTime / $collectDue->count() * 100) : null,
            'avg_days_to_pay' => $payDays->count() > 0 ? (int) round($payDays->sum() / $payDays->count()) : null,
            'paid_on_time_percent' => $payDue->count() > 0 ? (int) round($payOnTime / $payDue->count() * 100) : null,
        ];
    }

    /**
     * Monthly cash-in / cash-out / net plus the running cumulative net.
     *
     * @param  Collection<int, Inflow>  $inflows
     * @param  Collection<int, Cost>  $costs
     * @return list<array<string, mixed>>
     */
    private function overTime(Collection $inflows, Collection $costs, string $currency): array
    {
        $periods = [];
        foreach ($inflows as $i) {
            $m = $i->date->format('Y-m');
            $periods[$m]['in'] = ($periods[$m]['in'] ?? 0) + $i->signedMinor();
        }
        foreach ($costs as $c) {
            $m = $c->date->format('Y-m');
            $periods[$m]['out'] = ($periods[$m]['out'] ?? 0) + $c->total_amount->getMinorAmount();
        }
        ksort($periods);

        $cumulative = 0;
        $out = [];
        foreach ($periods as $period => $vals) {
            $in = (int) ($vals['in'] ?? 0);
            $cost = (int) ($vals['out'] ?? 0);
            $cumulative += $in - $cost;
            $out[] = [
                'period' => $period,
                'cash_in' => Money::fromMinor($in, $currency)->jsonSerialize(),
                'cash_out' => Money::fromMinor($cost, $currency)->jsonSerialize(),
                'net' => Money::fromMinor($in - $cost, $currency)->jsonSerialize(),
                'cumulative_net' => Money::fromMinor($cumulative, $currency)->jsonSerialize(),
            ];
        }

        return $out;
    }

    /**
     * Cash in vs cash out per category (sorted by combined volume).
     *
     * @param  Collection<int, Inflow>  $inflows
     * @param  Collection<int, Cost>  $costs
     * @return list<array<string, mixed>>
     */
    private function byCategory(Collection $inflows, Collection $costs, string $currency): array
    {
        $cats = [];
        foreach ($inflows as $i) {
            $name = $i->category ?? '—';
            $cats[$name]['in'] = ($cats[$name]['in'] ?? 0) + $i->signedMinor();
        }
        foreach ($costs as $c) {
            $name = $c->category ?? '—';
            $cats[$name]['out'] = ($cats[$name]['out'] ?? 0) + $c->total_amount->getMinorAmount();
        }

        $rows = [];
        foreach ($cats as $name => $vals) {
            $in = (int) ($vals['in'] ?? 0);
            $out = (int) ($vals['out'] ?? 0);
            $rows[] = [
                'category' => (string) $name,
                'cash_in' => Money::fromMinor($in, $currency)->jsonSerialize(),
                'cash_out' => Money::fromMinor($out, $currency)->jsonSerialize(),
                '_volume' => $in + $out,
            ];
        }
        usort($rows, fn (array $a, array $b) => $b['_volume'] <=> $a['_volume']);

        return array_map(fn (array $r) => ['category' => $r['category'], 'cash_in' => $r['cash_in'], 'cash_out' => $r['cash_out']], $rows);
    }

    /**
     * Paid-cost outflow grouped by category, largest first.
     *
     * @param  Collection<int, Cost>  $costs
     * @return list<array<string, mixed>>
     */
    private function topOutflowCategories(Collection $costs, string $currency): array
    {
        return array_values($costs->groupBy(fn (Cost $c) => $c->category ?? '—')
            ->map(fn ($group, $name) => [
                'category' => (string) $name,
                'amount' => Money::fromMinor((int) $group->sum(fn (Cost $c) => $c->total_amount->getMinorAmount()), $currency)->jsonSerialize(),
                'count' => $group->count(),
            ])->sortByDesc(fn (array $r) => $r['amount']['minor'])->values()->all());
    }

    /**
     * @param  list<string>  $excluded
     * @return list<array<string, mixed>>
     */
    private function topReceivables(array $excluded, string $currency): array
    {
        $rows = Inflow::query()
            ->where('status', InflowStatus::Pending)
            ->where('is_credit_note', false)
            ->where(fn ($q) => $q->whereNull('customer_id')->orWhereNotIn('customer_id', $excluded))
            ->orderByDesc('amount')
            ->limit(10)
            ->get(['id', 'customer_id', 'amount', 'date', 'due_date', 'reference', 'notes']);

        $names = Customer::query()->whereIn('id', $rows->pluck('customer_id')->filter()->unique())
            ->pluck('company_name', 'id');
        $today = Carbon::today();

        return array_values($rows->map(fn (Inflow $i) => [
            'id' => $i->getKey(),
            'counterparty' => $i->customer_id !== null ? ($names[$i->customer_id] ?? null) : null,
            'description' => $i->notes,
            'amount' => $i->amount->jsonSerialize(),
            'date' => $i->date->toIso8601String(),
            'due_date' => $i->due_date?->toIso8601String(),
            'is_overdue' => $i->due_date !== null && $i->due_date->lt($today),
            'days_outstanding' => (int) round(($i->due_date ?? $i->date)->diffInDays($today, false)),
            'reference' => $i->reference,
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topPayables(string $currency): array
    {
        $rows = Cost::query()
            ->where('status', '!=', CostStatus::Paid)
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get(['id', 'supplier_id', 'total_amount', 'date', 'due_date', 'reference', 'description']);

        $names = Supplier::query()->whereIn('id', $rows->pluck('supplier_id')->filter()->unique())
            ->pluck('company_name', 'id');
        $today = Carbon::today();

        return array_values($rows->map(fn (Cost $c) => [
            'id' => $c->getKey(),
            'counterparty' => $c->supplier_id !== null ? ($names[$c->supplier_id] ?? null) : null,
            'description' => $c->description,
            'amount' => $c->total_amount->jsonSerialize(),
            'date' => $c->date->toIso8601String(),
            'due_date' => $c->due_date?->toIso8601String(),
            'is_overdue' => $c->due_date !== null && $c->due_date->lt($today),
            'days_outstanding' => (int) round(($c->due_date ?? $c->date)->diffInDays($today, false)),
            'reference' => $c->reference,
        ])->all());
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
