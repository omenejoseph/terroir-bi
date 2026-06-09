<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CostStatus;
use App\Enums\InflowStatus;
use App\Models\Cost;
use App\Models\Inflow;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Cash-flow picture: 12 months of historical cash-in (received inflows, credit
 * notes negative) vs cash-out (costs by date), a 6-month projection (3-month
 * moving average + revenue trend, costs nudged for inflation), and the pending
 * pipeline (expected receivables / payables).
 */
class CashFlowForecastQuery
{
    private const HISTORY_MONTHS = 12;

    private const FORECAST_MONTHS = 6;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $currency = $this->currency();
        $now = Carbon::now();
        $start = $now->copy()->startOfMonth()->subMonths(self::HISTORY_MONTHS - 1);
        $end = $now->copy()->endOfMonth();

        $revByMonth = [];
        foreach (Inflow::query()->where('status', InflowStatus::Received->value)->whereBetween('date', [$start, $end])->get(['date', 'amount', 'is_credit_note']) as $i) {
            $key = $i->date->format('Y-m');
            $revByMonth[$key] = ($revByMonth[$key] ?? 0) + $i->signedMinor();
        }

        $costByMonth = [];
        foreach (Cost::query()->whereBetween('date', [$start, $end])->get(['date', 'total_amount']) as $c) {
            $key = $c->date->format('Y-m');
            $costByMonth[$key] = ($costByMonth[$key] ?? 0) + $c->total_amount->getMinorAmount();
        }

        $historical = [];
        for ($i = 0; $i < self::HISTORY_MONTHS; $i++) {
            $month = $start->copy()->addMonths($i)->format('Y-m');
            $rev = $revByMonth[$month] ?? 0;
            $cost = $costByMonth[$month] ?? 0;
            $historical[] = ['month' => $month, 'revenue' => $rev, 'costs' => $cost, 'net' => $rev - $cost];
        }

        $forecast = $this->forecast($historical, $start);

        return [
            'currency' => $currency,
            'historical' => array_map(fn (array $m) => $this->present($m, $currency, false), $historical),
            'forecast' => array_map(fn (array $m) => $this->present($m, $currency, true), $forecast),
            'pending' => $this->pending($currency),
            'summary' => $this->summary($historical, $currency),
        ];
    }

    /**
     * @param  list<array{month: string, revenue: int, costs: int, net: int}>  $historical
     * @return list<array{month: string, revenue: int, costs: int, net: int}>
     */
    private function forecast(array $historical, Carbon $start): array
    {
        $recent = array_slice($historical, -3);
        $avgRev = (int) round(array_sum(array_column($recent, 'revenue')) / max(1, count($recent)));
        $avgCost = (int) round(array_sum(array_column($recent, 'costs')) / max(1, count($recent)));

        $first = $recent[0]['revenue'] ?? 0;
        $last = $recent[count($recent) - 1]['revenue'] ?? 0;
        $growth = $first > 0 ? max(-0.5, min(0.5, ($last - $first) / $first / max(1, count($recent) - 1))) : 0.0;

        $out = [];
        for ($i = 1; $i <= self::FORECAST_MONTHS; $i++) {
            $month = $start->copy()->addMonths(self::HISTORY_MONTHS - 1 + $i)->format('Y-m');
            $rev = (int) round($avgRev * (1 + $growth * $i));
            $cost = (int) round($avgCost * 1.02); // small inflation nudge
            $out[] = ['month' => $month, 'revenue' => $rev, 'costs' => $cost, 'net' => $rev - $cost];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function pending(string $currency): array
    {
        $receivable = (int) Inflow::query()->where('status', InflowStatus::Pending->value)->where('is_credit_note', false)->sum('amount');
        $receivableCount = Inflow::query()->where('status', InflowStatus::Pending->value)->count();
        $payable = (int) Cost::query()->where('status', '!=', CostStatus::Paid->value)->sum('total_amount');
        $payableCount = Cost::query()->where('status', '!=', CostStatus::Paid->value)->count();

        return [
            'receivable' => Money::fromMinor($receivable, $currency)->jsonSerialize(),
            'receivable_count' => $receivableCount,
            'payable' => Money::fromMinor($payable, $currency)->jsonSerialize(),
            'payable_count' => $payableCount,
            'net' => Money::fromMinor($receivable - $payable, $currency)->jsonSerialize(),
        ];
    }

    /**
     * @param  list<array{month: string, revenue: int, costs: int, net: int}>  $historical
     * @return array<string, mixed>
     */
    private function summary(array $historical, string $currency): array
    {
        $n = max(1, count($historical));
        $avgRev = (int) round(array_sum(array_column($historical, 'revenue')) / $n);
        $avgCost = (int) round(array_sum(array_column($historical, 'costs')) / $n);

        $recent = array_slice($historical, -3);
        $first = $recent[0]['revenue'] ?? 0;
        $last = $recent[count($recent) - 1]['revenue'] ?? 0;
        $growth = $first > 0 ? ($last - $first) / $first * 100 : 0.0;

        return [
            'avg_monthly_revenue' => Money::fromMinor($avgRev, $currency)->jsonSerialize(),
            'avg_monthly_costs' => Money::fromMinor($avgCost, $currency)->jsonSerialize(),
            'avg_monthly_net' => Money::fromMinor($avgRev - $avgCost, $currency)->jsonSerialize(),
            'revenue_growth_percent' => number_format($growth, 2, '.', ''),
        ];
    }

    /**
     * @param  array{month: string, revenue: int, costs: int, net: int}  $month
     * @return array<string, mixed>
     */
    private function present(array $month, string $currency, bool $projection): array
    {
        return [
            'month' => $month['month'],
            'revenue' => Money::fromMinor($month['revenue'], $currency)->jsonSerialize(),
            'costs' => Money::fromMinor($month['costs'], $currency)->jsonSerialize(),
            'net' => Money::fromMinor($month['net'], $currency)->jsonSerialize(),
            'is_projection' => $projection,
        ];
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
