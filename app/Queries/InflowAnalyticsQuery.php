<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InflowStatus;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\Inflow;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cash-in analytics for a period: invoiced / collected / pending receivables,
 * net cash flow vs costs, days-to-collect, inflow trend, category split and
 * customer revenue. Mirrors {@see CostAnalyticsQuery} (the money-out side).
 */
class InflowAnalyticsQuery
{
    public const INVOICE_CATEGORY = 'Invoice';

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Carbon $from, Carbon $to): array
    {
        $currency = $this->currency();

        $inflows = Inflow::query()->whereBetween('date', [$from, $to])
            ->get(['date', 'amount', 'category', 'status', 'customer_id', 'is_credit_note', 'received_at']);

        $costsTotal = (int) Cost::query()->whereBetween('date', [$from, $to])
            ->get(['total_amount'])->sum(fn (Cost $c) => $c->total_amount->getMinorAmount());

        // Cash in = received inflows (credit notes count negative).
        $cashIn = (int) $inflows->where('status', InflowStatus::Received)
            ->sum(fn (Inflow $i) => $i->signedMinor());

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'invoiced' => $this->card($inflows, null, $currency),
            'collected' => $this->card($inflows, InflowStatus::Received, $currency),
            'pending' => $this->card($inflows, InflowStatus::Pending, $currency),
            'net_cash_flow' => [
                'net' => Money::fromMinor($cashIn - $costsTotal, $currency)->jsonSerialize(),
                'inflows' => Money::fromMinor($cashIn, $currency)->jsonSerialize(),
                'costs' => Money::fromMinor($costsTotal, $currency)->jsonSerialize(),
            ],
            'avg_days_to_collect' => $this->avgDaysToCollect($inflows),
            'avg_inflow' => $this->avgInflow($inflows, $currency),
            'by_category' => $this->grouped($inflows, $currency),
            'by_customer' => $this->byCustomer($inflows, $currency),
            'over_time' => $this->monthly($inflows, $currency),
            'cash_flow' => $this->cashFlow($inflows, $from, $to, $currency),
        ];
    }

    /**
     * Invoiced / Collected / Pending card: total + count over the 'Invoice'
     * category, optionally narrowed to a status.
     *
     * @param  Collection<int, Inflow>  $inflows
     * @return array<string, mixed>
     */
    private function card(Collection $inflows, ?InflowStatus $status, string $currency): array
    {
        $rows = $inflows->where('category', self::INVOICE_CATEGORY);
        if ($status !== null) {
            $rows = $rows->where('status', $status);
        }

        return [
            'total' => Money::fromMinor((int) $rows->sum(fn (Inflow $i) => $i->amount->getMinorAmount()), $currency)->jsonSerialize(),
            'count' => $rows->count(),
        ];
    }

    /**
     * Mean days from invoice date to received date, over collected invoices.
     *
     * @param  Collection<int, Inflow>  $inflows
     * @return array<string, mixed>
     */
    private function avgDaysToCollect(Collection $inflows): array
    {
        $collected = $inflows->where('category', self::INVOICE_CATEGORY)
            ->where('status', InflowStatus::Received)
            ->filter(fn (Inflow $i) => $i->received_at !== null);
        $count = $collected->count();
        $days = (float) $collected->sum(fn (Inflow $i) => (float) $i->date->diffInDays($i->received_at));

        return ['days' => $count > 0 ? round($days / $count, 1) : null, 'count' => $count];
    }

    /**
     * @param  Collection<int, Inflow>  $inflows
     * @return array<string, mixed>
     */
    private function avgInflow(Collection $inflows, string $currency): array
    {
        $count = $inflows->count();
        $sum = (int) $inflows->sum(fn (Inflow $i) => $i->amount->getMinorAmount());

        return ['avg' => Money::fromMinor($count > 0 ? intdiv($sum, $count) : 0, $currency)->jsonSerialize()];
    }

    /**
     * @param  Collection<int, Inflow>  $inflows
     * @return list<array<string, mixed>>
     */
    private function grouped(Collection $inflows, string $currency): array
    {
        return array_values($inflows->groupBy(fn (Inflow $i) => $i->category ?? '—')
            ->map(fn ($group, $name) => [
                'name' => (string) $name,
                'total' => Money::fromMinor((int) $group->sum(fn (Inflow $i) => $i->amount->getMinorAmount()), $currency)->jsonSerialize(),
            ])->sortByDesc(fn (array $r) => $r['total']['minor'])->values()->all());
    }

    /**
     * @param  Collection<int, Inflow>  $inflows
     * @return list<array<string, mixed>>
     */
    private function byCustomer(Collection $inflows, string $currency): array
    {
        $byId = $inflows->whereNotNull('customer_id')->groupBy('customer_id');
        $names = Customer::query()->whereIn('id', $byId->keys())->pluck('company_name', 'id');

        return array_values($byId->map(fn ($group, $id) => [
            'customer_id' => (string) $id,
            'company_name' => $names[$id] ?? null,
            'total' => Money::fromMinor((int) $group->sum(fn (Inflow $i) => $i->amount->getMinorAmount()), $currency)->jsonSerialize(),
        ])->sortByDesc(fn (array $r) => $r['total']['minor'])->values()->all());
    }

    /**
     * @param  Collection<int, Inflow>  $inflows
     * @return list<array{month: string, total: array<string, mixed>}>
     */
    private function monthly(Collection $inflows, string $currency): array
    {
        $byMonth = [];
        foreach ($inflows as $i) {
            $month = $i->date->format('Y-m');
            $byMonth[$month] = ($byMonth[$month] ?? 0) + $i->amount->getMinorAmount();
        }
        ksort($byMonth);

        $out = [];
        foreach ($byMonth as $month => $minor) {
            $out[] = ['month' => $month, 'total' => Money::fromMinor((int) $minor, $currency)->jsonSerialize()];
        }

        return $out;
    }

    /**
     * Monthly cash in (received inflows) vs cash out (costs) and the net.
     *
     * @param  Collection<int, Inflow>  $inflows
     * @return list<array<string, mixed>>
     */
    private function cashFlow(Collection $inflows, Carbon $from, Carbon $to, string $currency): array
    {
        $costs = Cost::query()->whereBetween('date', [$from, $to])->get(['date', 'total_amount']);

        $months = [];
        foreach ($inflows->where('status', InflowStatus::Received) as $i) {
            $m = $i->date->format('Y-m');
            $months[$m]['in'] = ($months[$m]['in'] ?? 0) + $i->signedMinor();
        }
        foreach ($costs as $c) {
            $m = $c->date->format('Y-m');
            $months[$m]['out'] = ($months[$m]['out'] ?? 0) + $c->total_amount->getMinorAmount();
        }
        ksort($months);

        $out = [];
        foreach ($months as $month => $vals) {
            $in = (int) ($vals['in'] ?? 0);
            $cost = (int) ($vals['out'] ?? 0);
            $out[] = [
                'month' => $month,
                'inflows' => Money::fromMinor($in, $currency)->jsonSerialize(),
                'costs' => Money::fromMinor($cost, $currency)->jsonSerialize(),
                'net' => Money::fromMinor($in - $cost, $currency)->jsonSerialize(),
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
