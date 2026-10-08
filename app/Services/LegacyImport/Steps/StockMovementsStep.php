<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\StockMovementType;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;
use Illuminate\Support\Facades\DB;

/**
 * StockMovement → stock_movements. Written straight to the table (never via
 * StockLedger), so inventory_items.current_stock stays the legacy figure. ORDER_DEDUCT
 * references are order numbers and line up verbatim with the imported orders.
 */
class StockMovementsStep extends AbstractStep
{
    public function name(): string
    {
        return 'stock_movements';
    }

    public function dependsOn(): array
    {
        return ['users', 'inventory', 'orders'];
    }

    public function run(ImportContext $ctx): void
    {
        $orphanRefs = 0;
        $orderNumbers = DB::table('orders')->where('tenant_id', $ctx->tenant->getKey())->pluck('order_number')->flip();

        foreach ($this->rows($ctx, 'StockMovement') as $r) {
            $ctx->report->read('stock_movements');

            $item = $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId);
            $type = StockMovementType::tryFrom((string) $r->type);
            if ($item === null || $type === null) {
                $ctx->report->skipped('stock_movements', "movement {$r->id}: ".($item === null ? 'inventory item not migrated' : "unknown type '{$r->type}'"));

                continue;
            }

            $reference = Normalize::str($r->reference);
            if ($type === StockMovementType::OrderDeduct && $reference !== null && ! isset($orderNumbers[$reference])) {
                $orphanRefs++; // old numbering / deleted orders: kept as unlinked history
            }

            $this->put($ctx, 'StockMovement', (string) $r->id, 'stock_movements', [
                'inventory_item_id' => $item,
                'type' => $type->value,
                'quantity' => Normalize::dec($r->quantity, 3),
                'unit' => Normalize::str($r->unit),
                'note' => Normalize::str($r->note),
                'reference' => $reference,
                'is_reconciliation' => Normalize::bool($r->isReconciliation),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'stock_movements'),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('stock_movements');
        }

        if ($orphanRefs > 0) {
            $ctx->report->warn('stock_movements', "{$orphanRefs} ORDER_DEDUCT movements reference an order number that no longer exists; imported as unlinked history");
        }

        $this->drift($ctx);
    }

    /** Informational: items whose legacy stock isn't explained by their movement history. */
    private function drift(ImportContext $ctx): void
    {
        $tenantId = (string) $ctx->tenant->getKey();

        $rows = DB::table('inventory_items as i')
            ->leftJoin('stock_movements as m', 'm.inventory_item_id', '=', 'i.id')
            ->where('i.tenant_id', $tenantId)
            ->groupBy('i.id', 'i.current_stock')
            ->havingRaw('abs(i.current_stock - coalesce(sum(m.quantity), 0)) > 0.001')
            ->pluck('i.id');

        $total = DB::table('inventory_items')->where('tenant_id', $tenantId)->count();
        $ctx->report->warn('stock_movements', "{$rows->count()} of {$total} items have current_stock ≠ sum of movements (legacy stock kept as authoritative; opening balances are not movements)");
    }
}
