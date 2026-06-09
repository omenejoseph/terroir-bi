<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Support\OrderCadence;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Forward-looking commercial analytics for one customer: period revenue, YoY
 * growth, an annualised projection, the expected next order, and a projection
 * for the coming quarter. Revenue is the sum of non-consignment order totals
 * (consistent with CustomerInsightsQuery / OrderAnalyticsQuery).
 */
class CustomerOrderAnalyticsQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Customer $customer): array
    {
        $currency = $this->currency();
        $now = Carbon::now();

        $base = fn (): Builder => Order::query()
            ->where('customer_id', $customer->getKey())
            ->where('is_consignment', false);

        $totalRevenue = (int) $base()->sum('total_amount');
        $thisYear = (int) $base()->whereYear('created_at', $now->year)->sum('total_amount');
        $lastYear = (int) $base()->whereYear('created_at', $now->year - 1)->sum('total_amount');
        $lastOrderAt = $base()->max('created_at');

        // YoY growth: trailing 3 months vs. the same 3 months a year earlier.
        $recent = $this->revenueBetween($base(), $now->copy()->subMonths(3), $now);
        $prior = $this->revenueBetween(
            $base(),
            $now->copy()->subMonths(3)->subYear(),
            $now->copy()->subYear(),
        );
        $yoy = $prior > 0 ? ($recent - $prior) / $prior : 0.0;

        // Annualise this year's revenue by the fraction of the year elapsed.
        $daysInYear = $now->isLeapYear() ? 366 : 365;
        $fractionElapsed = $now->dayOfYear / $daysInYear;
        $annualProjection = $fractionElapsed > 0 ? (int) round($thisYear / $fractionElapsed) : 0;

        // Next quarter: the same 3 months last year, grown by YoY.
        $priorQuarter = $this->revenueBetween(
            $base(),
            $now->copy()->addMonth()->startOfMonth()->subYear(),
            $now->copy()->addMonths(3)->endOfMonth()->subYear(),
        );
        $nextQuarterProjection = (int) round($priorQuarter * max(0.0, 1 + $yoy));

        return [
            'total_revenue' => Money::fromMinor($totalRevenue, $currency)->jsonSerialize(),
            'this_year' => Money::fromMinor($thisYear, $currency)->jsonSerialize(),
            'last_year' => Money::fromMinor($lastYear, $currency)->jsonSerialize(),
            'last_order_date' => is_string($lastOrderAt) ? $lastOrderAt : null,
            'yoy_growth_percent' => number_format($yoy * 100, 2, '.', ''),
            'annual_projection' => Money::fromMinor($annualProjection, $currency)->jsonSerialize(),
            'expected_next_order_date' => $this->expectedNext($customer)?->toIso8601String(),
            'next_quarter_projection' => Money::fromMinor($nextQuarterProjection, $currency)->jsonSerialize(),
        ];
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function revenueBetween(Builder $query, Carbon $from, Carbon $to): int
    {
        return (int) $query->whereBetween('created_at', [$from, $to])->sum('total_amount');
    }

    private function expectedNext(Customer $customer): ?Carbon
    {
        /** @var list<Carbon> $dates */
        $dates = Order::query()
            ->where('customer_id', $customer->getKey())
            ->where('is_consignment', false)
            ->get(['created_at', 'backorder_date'])
            ->map(fn (Order $o) => $o->backorder_date ?? $o->created_at ?? Carbon::now())
            ->sort()
            ->values()
            ->all();

        return OrderCadence::expectedNext($dates);
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
