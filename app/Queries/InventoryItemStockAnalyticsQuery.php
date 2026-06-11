<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Services\Orders\CogsSnapshot;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-item stock analytics for the inventory "Stock" tab. Everything is expressed
 * per bottle. Realized price/rebate/margin come from this item's sales order lines
 * over the trailing 12 months; warehouse exits + channels come from stock
 * movements for the selected period; sales revenue/margin for the period come
 * from orders (movements don't store the order line).
 */
class InventoryItemStockAnalyticsQuery
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CogsSnapshot $cogs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(InventoryItem $item, string $period): array
    {
        $currency = $this->currency();
        $bpc = max(1, (int) $item->bottles_per_case);
        $caseUnit = in_array(strtolower((string) $item->unit), ['case', 'cases'], true);
        $toBottles = fn (float $qty): float => $caseUnit ? $qty * $bpc : $qty;

        $stockBottles = (int) round($toBottles((float) $item->current_stock));
        $minBottles = $item->min_stock !== null ? (int) round($toBottles((float) $item->min_stock)) : null;
        $costPerBottle = $this->cogs->perBottle($item);
        $selling = $item->default_price;

        [$from, $to, $days] = $this->window($period);

        return [
            'period' => $period,
            'current' => [
                'stock_bottles' => $stockBottles,
                'unit' => $item->unit,
                'bottles_per_case' => $bpc,
                'min_stock_bottles' => $minBottles,
                'cost_per_bottle' => $costPerBottle?->jsonSerialize(),
                'selling_per_bottle' => $selling?->jsonSerialize(),
            ],
            'realized' => $this->realized($item, $bpc, $stockBottles, $costPerBottle, $selling, $currency),
            'exits' => $this->exits($item, $toBottles, $from, $to, $days, $stockBottles, $costPerBottle, $currency),
            'channels' => $this->channels($item, $toBottles, $from, $to),
        ];
    }

    /**
     * Realized price/rebate/margin over the trailing 12 months, per bottle.
     *
     * @return array<string, mixed>
     */
    private function realized(
        InventoryItem $item,
        int $bpc,
        int $stockBottles,
        ?Money $costPerBottle,
        ?Money $selling,
        string $currency,
    ): array {
        $from = Carbon::now()->subYear();

        $base = fn (): Builder => OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.inventory_item_id', $item->getKey())
            ->where('orders.is_consignment', false)
            ->where('orders.created_at', '>=', $from);

        $revenue = (int) $base()->sum('order_items.total');
        $bottlesSold = $this->toBottlesSum($base(), $bpc);

        if ($bottlesSold <= 0) {
            return [
                'mean_price' => null,
                'rebate_percent' => null,
                'rebate_amount' => null,
                'margin_percent' => null,
                'margin_amount' => null,
                'sales_value' => Money::zero($currency)->jsonSerialize(),
                'bottles_sold' => 0,
            ];
        }

        $meanPrice = (int) round($revenue / $bottlesSold);

        // Realized cost per bottle from snapshotted line costs (lines with a known cost).
        $cogs = (int) $base()->whereNotNull('order_items.cost_per_unit')
            ->sum(DB::raw('order_items.cost_per_unit * order_items.quantity'));
        $bottlesWithCost = $this->toBottlesSum($base()->whereNotNull('order_items.cost_per_unit'), $bpc);
        $costPerBottleMinor = $bottlesWithCost > 0
            ? (int) round($cogs / $bottlesWithCost)
            : ($costPerBottle?->getMinorAmount() ?? 0);
        $marginAmount = $meanPrice - $costPerBottleMinor;

        // Rebate is measured against the list (default) price per bottle.
        $sellingMinor = $selling?->getMinorAmount() ?? 0;
        $rebateAmount = $sellingMinor - $meanPrice;

        return [
            'mean_price' => Money::fromMinor($meanPrice, $currency)->jsonSerialize(),
            'rebate_percent' => $sellingMinor > 0 ? number_format($rebateAmount / $sellingMinor * 100, 1, '.', '') : null,
            'rebate_amount' => $sellingMinor > 0 ? Money::fromMinor($rebateAmount, $currency)->jsonSerialize() : null,
            'margin_percent' => number_format($marginAmount / $meanPrice * 100, 1, '.', ''),
            'margin_amount' => Money::fromMinor($marginAmount, $currency)->jsonSerialize(),
            'sales_value' => Money::fromMinor($stockBottles * $meanPrice, $currency)->jsonSerialize(),
            'bottles_sold' => $bottlesSold,
        ];
    }

    /**
     * Warehouse exits for the period: bottles out (all channels), cost of those
     * exits, sales revenue/margin (orders), velocity and days-of-stock-left.
     *
     * @param  callable(float): float  $toBottles
     * @return array<string, mixed>
     */
    private function exits(
        InventoryItem $item,
        callable $toBottles,
        Carbon $from,
        Carbon $to,
        int $days,
        int $stockBottles,
        ?Money $costPerBottle,
        string $currency,
    ): array {
        $exitedRaw = (float) StockMovement::query()
            ->where('inventory_item_id', $item->getKey())
            ->where('quantity', '<', 0)
            ->whereBetween('created_at', [$from, $to])
            ->sum('quantity');
        $bottlesExited = (int) round($toBottles(abs($exitedRaw)));

        $saleLines = fn (): Builder => OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.inventory_item_id', $item->getKey())
            ->where('orders.is_consignment', false)
            ->whereBetween('orders.created_at', [$from, $to]);
        $revenue = (int) $saleLines()->sum('order_items.total');
        $saleCogs = (int) $saleLines()->whereNotNull('order_items.cost_per_unit')
            ->sum(DB::raw('order_items.cost_per_unit * order_items.quantity'));

        $velocity = $bottlesExited / max(1, $days);
        $daysLeft = $bottlesExited > 0 ? (int) round($stockBottles * $days / $bottlesExited) : null;

        return [
            'bottles_exited' => $bottlesExited,
            'cost_of_exits' => $costPerBottle !== null
                ? Money::fromMinor($bottlesExited * $costPerBottle->getMinorAmount(), $currency)->jsonSerialize()
                : null,
            'revenue_realized' => $revenue > 0 ? Money::fromMinor($revenue, $currency)->jsonSerialize() : null,
            'mean_margin_percent' => $revenue > 0
                ? number_format(($revenue - $saleCogs) / $revenue * 100, 1, '.', '')
                : null,
            'velocity_per_day' => number_format($velocity, 2, '.', ''),
            'days_of_stock_left' => $daysLeft,
        ];
    }

    /**
     * Exit-by-channel (movement type) for the period, in bottles.
     *
     * @param  callable(float): float  $toBottles
     * @return list<array{channel: string, bottles: int}>
     */
    private function channels(InventoryItem $item, callable $toBottles, Carbon $from, Carbon $to): array
    {
        $rows = StockMovement::query()
            ->where('inventory_item_id', $item->getKey())
            ->where('quantity', '<', 0)
            ->whereBetween('created_at', [$from, $to])
            ->get(['type', 'quantity']);

        /** @var array<string, float> $byChannel */
        $byChannel = [];
        foreach ($rows as $movement) {
            $channel = match ($movement->type) {
                StockMovementType::OrderDeduct => 'sales',
                StockMovementType::ProductionOut => 'production',
                default => 'manual',
            };
            $byChannel[$channel] = ($byChannel[$channel] ?? 0) + $toBottles(abs((float) $movement->quantity));
        }

        $out = [];
        foreach ($byChannel as $channel => $bottles) {
            $rounded = (int) round($bottles);
            if ($rounded > 0) {
                $out[] = ['channel' => $channel, 'bottles' => $rounded];
            }
        }

        return $out;
    }

    /**
     * Total bottles for a set of order lines: bottle-unit lines as-is, case-unit
     * lines × bottles_per_case. (Avoids an interpolated SQL CASE expression.)
     *
     * @param  Builder<OrderItem>  $query
     */
    private function toBottlesSum(Builder $query, int $bpc): int
    {
        $bottleUnits = (int) (clone $query)->where('order_items.unit_type', '!=', 'cases')->sum('order_items.quantity');
        $caseUnits = (int) (clone $query)->where('order_items.unit_type', 'cases')->sum('order_items.quantity');

        return $bottleUnits + $caseUnits * $bpc;
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: int} [from, to, days]
     */
    private function window(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now, 1],
            'mtd' => [$now->copy()->startOfMonth(), $now, max(1, $now->day)],
            'ytd' => [$now->copy()->startOfYear(), $now, max(1, $now->dayOfYear)],
            '90d' => [$now->copy()->subDays(90), $now, 90],
            default => [$now->copy()->subDays(30), $now, 30],
        };
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
