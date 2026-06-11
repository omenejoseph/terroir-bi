<?php

namespace App\Filament\Widgets;

use App\Queries\PlatformDashboardQuery;
use Filament\Widgets\ChartWidget;

/**
 * How the tenant base distributes across plans — shows which plans carry the
 * platform and how many tenants are still unassigned.
 */
class TenantsByPlanChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Tenants by plan';

    /** Brand-toned slices (wine first, then supporting hues). */
    private const COLORS = ['#7a1f2b', '#b4434f', '#d98a92', '#52525b', '#a1a1aa', '#d4d4d8'];

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $byPlan = app(PlatformDashboardQuery::class)->tenantsByPlan();

        return [
            'datasets' => [
                [
                    'data' => array_values($byPlan),
                    'backgroundColor' => array_slice(
                        array_merge(self::COLORS, array_fill(0, max(0, count($byPlan) - count(self::COLORS)), '#e4e4e7')),
                        0,
                        max(count($byPlan), 1),
                    ),
                ],
            ],
            'labels' => array_keys($byPlan),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
