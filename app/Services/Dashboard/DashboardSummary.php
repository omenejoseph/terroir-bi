<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Queries\InventoryAnalyticsQuery;
use App\Support\Money\CurrencyRegistry;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Builds the aggregated dashboard payload, transport-agnostic so the same call
 * can back the API, a Livewire component, or an Inertia page.
 *
 * Metrics we already have (customers, low stock, stock watch, product names) are
 * real and read-optimised; metrics from modules not yet built (orders, revenue)
 * are deterministic placeholders generated here — swap each builder for a real
 * query as the module lands, and every transport updates at once.
 */
class DashboardSummary
{
    private const RANGE_DAYS = ['7D' => 7, '30D' => 30, '90D' => 90, '1Y' => 365, 'ALL' => 540];

    public function __construct(
        private readonly InventoryAnalyticsQuery $analytics,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?string $range): array
    {
        $range = is_string($range) && isset(self::RANGE_DAYS[$range]) ? $range : '30D';
        $days = self::RANGE_DAYS[$range];

        $orders = $this->ordersSeries($days);
        $revenue = $this->revenueSeries($days);
        $totalOrders = array_sum(array_column($orders, 'value'));
        $revenueTotal = array_sum(array_column($revenue, 'value'));

        return [
            'range' => $range,
            'currency' => $this->currency(),
            'stats' => [
                'total_orders' => $totalOrders,
                'customers' => Customer::query()->count(),
                'revenue' => $revenueTotal,
                'low_stock' => $this->analytics->lowStockCount(),
            ],
            'orders' => $orders,
            'revenue' => $revenue,
            'order_status' => $this->orderStatus($totalOrders),
            'top_products' => $this->topProducts(),
            'stock_watch' => $this->analytics->stockWatch(6),
            'recent_orders' => $this->recentOrders(),
        ];
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function ordersSeries(int $days): array
    {
        $points = min($days, 30);
        $step = max(1, intdiv($days, $points));
        $out = [];
        for ($i = $points - 1; $i >= 0; $i--) {
            $out[] = ['label' => $this->label($i * $step), 'value' => $this->wave($points - $i, 6, 4, 3)];
        }

        return $out;
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function revenueSeries(int $days): array
    {
        $points = min($days, 30);
        $step = max(1, intdiv($days, $points));
        $out = [];
        for ($i = $points - 1; $i >= 0; $i--) {
            // Minor units (cents).
            $out[] = ['label' => $this->label($i * $step), 'value' => ($this->wave($points - $i, 1800, 1400, 4) + 600) * 100];
        }

        return $out;
    }

    /**
     * @return list<array{key: string, value: int}>
     */
    private function orderStatus(int $total): array
    {
        $received = (int) round($total * 0.28);
        $inProcess = (int) round($total * 0.18);
        $ready = (int) round($total * 0.12);
        $shipped = max(0, $total - $received - $inProcess - $ready);

        return [
            ['key' => 'received', 'value' => $received],
            ['key' => 'inProcess', 'value' => $inProcess],
            ['key' => 'readyToShip', 'value' => $ready],
            ['key' => 'shipped', 'value' => $shipped],
        ];
    }

    /**
     * Real product names (so it feels live) with placeholder sales figures.
     *
     * @return list<array{name: string, value: int}>
     */
    private function topProducts(): array
    {
        $names = InventoryItem::query()
            ->where('is_for_sale', true)
            ->orderByDesc('current_stock')
            ->limit(5)
            ->pluck('name')
            ->all();

        if ($names === []) {
            $names = ['Premium Red Blend 2024', 'Plavac Mali 2021', 'Graševina 2022'];
        }

        $out = [];
        foreach (array_values($names) as $i => $name) {
            $out[] = ['name' => (string) $name, 'value' => $this->wave($i + 2, 7, 5, 1.4)];
        }
        usort($out, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $out;
    }

    /**
     * @return list<array{id: string, customer: string, items: int, total: int, status: string, date: string}>
     */
    private function recentOrders(): array
    {
        return [
            ['id' => 'ORD-20260042', 'customer' => 'Acme Corporation', 'items' => 5, 'total' => 9995, 'status' => 'received', 'date' => $this->label(0)],
            ['id' => 'ORD-20260041', 'customer' => 'Vinoteka Zagreb', 'items' => 12, 'total' => 24800, 'status' => 'shipped', 'date' => $this->label(1)],
            ['id' => 'ORD-20260040', 'customer' => 'Bistro Dalmatino', 'items' => 3, 'total' => 5400, 'status' => 'readyToShip', 'date' => $this->label(2)],
            ['id' => 'ORD-20260039', 'customer' => 'Hotel Adriatic', 'items' => 24, 'total' => 51200, 'status' => 'inProcess', 'date' => $this->label(3)],
        ];
    }

    private function wave(int $i, float $base, float $amp, float $period): int
    {
        $v = $base + $amp * sin($i / $period) + $amp * 0.45 * sin($i / 1.7 + 1);

        return (int) max(0, round($v));
    }

    private function label(int $daysAgo): string
    {
        return Carbon::now()->subDays($daysAgo)->format('M j');
    }

    private function currency(): string
    {
        return $this->tenant->current()?->settings->default_currency ?? CurrencyRegistry::default()->code;
    }
}
