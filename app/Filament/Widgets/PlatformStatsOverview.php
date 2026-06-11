<?php

namespace App\Filament\Widgets;

use App\Queries\PlatformDashboardQuery;
use App\Support\Money\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * The platform KPI row: tenant base, trial pipeline, revenue, seats and usage.
 * All numbers come from PlatformDashboardQuery — no DB reads here.
 */
class PlatformStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $query = app(PlatformDashboardQuery::class);
        $now = Carbon::now();

        $tenants = $query->tenantCounts($now);
        $mrr = $query->estimatedMrr();
        $activity = $query->orderActivity($now);
        $trialsEndingSoon = $query->trialsEndingSoonCount($now);

        return [
            Stat::make('Tenants', (string) $tenants['total'])
                ->description($tenants['new_this_month'].' new this month · '.$tenants['active'].' active')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary'),

            Stat::make('Trials', (string) $tenants['trial'])
                ->description($trialsEndingSoon.' ending within 14 days')
                ->descriptionIcon('heroicon-m-clock')
                ->color($trialsEndingSoon > 0 ? 'warning' : 'gray'),

            Stat::make('Est. MRR', Money::fromMinor($mrr['minor'], $mrr['currency'])->toMajor().' '.$mrr['currency'])
                ->description($mrr['paying_tenants'].' paying tenants')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('success'),

            Stat::make('Active users', (string) $query->activeUserCount())
                ->description('Across all tenants')
                ->descriptionIcon('heroicon-m-users')
                ->color('gray'),

            Stat::make('Orders (30d)', (string) $activity['total'])
                ->description('Platform-wide order volume')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->chart(array_map(fn (int $count): float => (float) $count, $activity['per_day']))
                ->chartColor('primary')
                ->color('gray'),
        ];
    }
}
