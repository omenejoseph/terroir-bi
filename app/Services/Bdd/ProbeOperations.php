<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\Order;
use App\Models\StockMovement;
use App\Queries\InventorySpendQuery;
use App\Services\Orders\ConsignmentService;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Built-in Then primitives (always available, read-only). Every probe runs
 * under the sandbox tenant context, so tenant-scoped queries are already
 * confined; probes return plain scalars/arrays for the assertion engine.
 */
class ProbeOperations
{
    /** Tables probe.db_count may touch — tenant-scoped tables only (guard rail). */
    private const COUNTABLE_TABLES = [
        'orders', 'order_items', 'stock_movements', 'inventory_items',
        'customers', 'notifications', 'consignment_reports', 'order_status_histories',
    ];

    /**
     * @return list<OperationSpec>
     */
    public static function specs(): array
    {
        return [
            new OperationSpec('probe.stock_of', 'probe', 'Current stock of an item, as a number in its storage unit.', [
                'item' => '$ref to an inventory item capture (required)',
            ]),
            new OperationSpec('probe.movements_of', 'probe', 'All stock movements of an item, oldest first: [{type, quantity, unit, reference, is_reconciliation}].', [
                'item' => '$ref to an inventory item capture (required)',
            ]),
            new OperationSpec('probe.order', 'probe', 'Snapshot of an order: {order_number, status, total_minor, shipping_cost_minor, shipping_paid_by_us, is_backorder, is_consignment, items: [{quantity, unit_type, unit_price_minor, total_minor, cost_per_unit_minor, custom_description}], status_history: [status,...]}. All money in integer minor units.', [
                'order' => '$ref to an order capture (required)',
            ]),
            new OperationSpec('probe.price_for', 'probe', 'The resolved per-bottle price (integer minor units) for a customer + item via the pricing cascade (customer price → tier → default+rebate).', [
                'customer' => '$ref to a customer capture (required)',
                'item' => '$ref to an inventory item capture (required)',
            ]),
            new OperationSpec('probe.consignment_tally', 'probe', 'Consignment totals for an order: {placed, sold, returned, remaining, revenue_minor} summed across lines (bottles).', [
                'order' => '$ref to a consignment order capture (required)',
            ]),
            new OperationSpec('probe.spend_summary', 'probe', 'Inventory exit report summary for the last 24h (sandbox only): {units_exited, movements, revenue_minor, cost_minor, distinct_skus}.', []),
            new OperationSpec('probe.notifications', 'probe', 'In-app notifications created during the run: {count, titles: [...], recipients: [user ids]} optionally filtered by type.', [
                'type' => 'string|null — e.g. NEW_ORDER, MENTION',
            ]),
            new OperationSpec('probe.db_count', 'probe', 'Row count of a tenant-scoped table within the sandbox. Allowed tables: '.implode(', ', self::COUNTABLE_TABLES).'.', [
                'table' => 'string — one of the allowed tables (required)',
            ]),
        ];
    }

    public function __construct(private readonly SandboxContext $sandbox) {}

    /**
     * @param  array<string, mixed>  $args  already interpolated ($refs resolved to models)
     */
    public function execute(string $key, array $args): mixed
    {
        return match ($key) {
            'probe.stock_of' => $this->stockOf($args),
            'probe.movements_of' => $this->movementsOf($args),
            'probe.order' => $this->order($args),
            'probe.price_for' => $this->priceFor($args),
            'probe.consignment_tally' => $this->consignmentTally($args),
            'probe.spend_summary' => $this->spendSummary(),
            'probe.notifications' => $this->notifications($args),
            'probe.db_count' => $this->dbCount($args),
            default => throw new InvalidArgumentException("Unknown probe operation [{$key}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function item(array $args, string $param = 'item'): InventoryItem
    {
        $item = $args[$param] ?? null;
        if (! $item instanceof InventoryItem) {
            throw new InvalidArgumentException("This probe needs a \$ref to an inventory item in [{$param}].");
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function stockOf(array $args): float
    {
        return (float) InventoryItem::query()
            ->whereKey($this->item($args)->getKey())
            ->firstOrFail()
            ->current_stock;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int, array<string, mixed>>
     */
    private function movementsOf(array $args): array
    {
        return StockMovement::query()
            ->where('inventory_item_id', $this->item($args)->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (StockMovement $m): array => [
                'type' => $m->type->value,
                'quantity' => (float) $m->quantity,
                'unit' => $m->unit,
                'reference' => $m->reference,
                'is_reconciliation' => (bool) $m->is_reconciliation,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function order(array $args): array
    {
        $ref = $args['order'] ?? null;
        if (! $ref instanceof Order) {
            throw new InvalidArgumentException('probe.order needs a $ref to an order capture.');
        }

        $order = Order::query()->whereKey($ref->getKey())->firstOrFail();
        $order->loadMissing('items', 'statusHistories');

        return [
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'total_minor' => $order->total_amount->getMinorAmount(),
            'shipping_cost_minor' => $order->shipping_cost?->getMinorAmount(),
            'shipping_paid_by_us' => (bool) $order->shipping_paid_by_us,
            'is_backorder' => (bool) $order->is_backorder,
            'is_consignment' => (bool) $order->is_consignment,
            'items' => $order->items->map(fn ($i): array => [
                'quantity' => $i->quantity,
                'unit_type' => $i->unit_type,
                'unit_price_minor' => $i->unit_price->getMinorAmount(),
                'total_minor' => $i->total->getMinorAmount(),
                'cost_per_unit_minor' => $i->cost_per_unit?->getMinorAmount(),
                'custom_description' => $i->custom_description,
            ])->values()->all(),
            'status_history' => $order->statusHistories->map(
                fn ($h) => $h->status->value,
            )->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function priceFor(array $args): int
    {
        $customer = $args['customer'] ?? null;
        if (! $customer instanceof Customer) {
            throw new InvalidArgumentException('probe.price_for needs a $ref to a customer capture.');
        }

        return app(PricingService::class)->resolve($customer, $this->item($args))->getMinorAmount();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, int>
     */
    private function consignmentTally(array $args): array
    {
        $order = $args['order'] ?? null;
        if (! $order instanceof Order) {
            throw new InvalidArgumentException('probe.consignment_tally needs a $ref to an order capture.');
        }

        $tally = app(ConsignmentService::class)->tally(
            Order::query()->whereKey($order->getKey())->firstOrFail(),
        );

        $sum = fn (string $field): int => (int) array_sum(array_map(fn (array $t) => $t[$field], $tally));

        return [
            'placed' => $sum('placed'),
            'sold' => $sum('sold'),
            'returned' => $sum('returned'),
            'remaining' => $sum('remaining'),
            'revenue_minor' => $sum('revenue_minor'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spendSummary(): array
    {
        $summary = app(InventorySpendQuery::class)->get(
            Carbon::now()->subDay(),
            Carbon::now()->addMinute(),
        )['summary'];

        return [
            'units_exited' => (int) $summary['units_exited'],
            'movements' => (int) $summary['movements'],
            'revenue_minor' => (int) ($summary['revenue']['minor'] ?? 0),
            'cost_minor' => (int) ($summary['cost_value']['minor'] ?? 0),
            'distinct_skus' => (int) $summary['distinct_skus'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function notifications(array $args): array
    {
        $query = Notification::query();

        if (isset($args['type'])) {
            $query->where('type', (string) $args['type']);
        }

        $rows = $query->get();

        return [
            'count' => $rows->count(),
            'titles' => $rows->pluck('title')->values()->all(),
            'recipients' => $rows->pluck('user_id')->unique()->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function dbCount(array $args): int
    {
        $table = (string) ($args['table'] ?? '');

        if (! in_array($table, self::COUNTABLE_TABLES, true)) {
            throw new InvalidArgumentException(
                'Guard rail: probe.db_count may only count: '.implode(', ', self::COUNTABLE_TABLES).'.',
            );
        }

        return DB::table($table)
            ->where('tenant_id', $this->sandbox->tenant->getKey())
            ->count();
    }
}
