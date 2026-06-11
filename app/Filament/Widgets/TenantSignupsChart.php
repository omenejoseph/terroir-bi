<?php

namespace App\Filament\Widgets;

use App\Queries\PlatformDashboardQuery;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * New tenants per month over the last year — the growth trend at a glance.
 */
class TenantSignupsChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Tenant signups';

    protected ?string $description = 'New tenants per month, last 12 months.';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $signups = app(PlatformDashboardQuery::class)->signupsPerMonth(Carbon::now());

        return [
            'datasets' => [
                [
                    'label' => 'New tenants',
                    'data' => array_values($signups),
                    'borderColor' => '#7a1f2b',
                    'backgroundColor' => 'rgba(122, 31, 43, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => array_keys($signups),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
