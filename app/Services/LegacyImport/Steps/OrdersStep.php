<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\OrderStatus;
use App\Enums\SalesUnit;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;
use Illuminate\Support\Facades\DB;

/**
 * Order (+ items, status history, notes, reactions, consignment reports).
 *
 * Order numbers are kept verbatim (legacy "266104" style). OrderNumberGenerator
 * only counts `ORD-`-prefixed numbers, so new orders start at ORD-00001 with no
 * collision against the legacy numbers.
 */
class OrdersStep extends AbstractStep
{
    public function name(): string
    {
        return 'orders';
    }

    public function dependsOn(): array
    {
        return ['users', 'inventory', 'customers'];
    }

    public function run(ImportContext $ctx): void
    {
        $this->orders($ctx);
        $this->items($ctx);
        $this->histories($ctx);
        $this->notes($ctx);
        $this->reactions($ctx);
        $this->consignment($ctx);
        $this->checkTotals($ctx);
    }

    private function orders(ImportContext $ctx): void
    {
        // Orders whose stock already left in the legacy ledger keep deduct_stock=true so later
        // edits stay consistent with it; everything else (backorders etc.) stays false.
        $deducted = $ctx->legacy->table('StockMovement')->where('type', 'ORDER_DEDUCT')->whereNotNull('reference')
            ->distinct()->pluck('reference')->flip();

        foreach ($this->rows($ctx, 'Order') as $r) {
            $ctx->report->read('orders');

            $customer = $ctx->ids->get('Customer', (string) $r->customerId);
            $status = OrderStatus::tryFrom((string) $r->status);
            if ($customer === null || $status === null) {
                $ctx->report->skipped('orders', "order {$r->orderNumber}: ".($customer === null ? 'customer not migrated' : "unknown status '{$r->status}'"));

                continue;
            }

            $this->put($ctx, 'Order', (string) $r->id, 'orders', [
                'order_number' => trim((string) $r->orderNumber),
                'status' => $status->value,
                'total_amount' => Normalize::minor($r->totalAmount),
                'notes' => Normalize::str($r->notes),
                'customer_id' => $customer,
                'created_by_id' => $ctx->userId((string) $r->createdById, 'orders'),
                'is_backorder' => Normalize::bool($r->isBackorder),
                'backorder_date' => Normalize::date($r->backorderDate),
                'shipping_cost' => Normalize::minorOrNull($r->shippingCost),
                'shipping_paid_by_us' => Normalize::bool($r->shippingPaidByUs),
                'is_consignment' => Normalize::bool($r->isConsignment),
                'consignment_closed_at' => Normalize::date($r->consignmentClosedAt),
                'last_stale_notified_at' => Normalize::date($r->lastStaleNotifiedAt),
                'deduct_stock' => isset($deducted[trim((string) $r->orderNumber)]),
                'is_ai_generated' => false,
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('orders');
        }
    }

    private function items(ImportContext $ctx): void
    {
        $units = 0;

        foreach ($this->rows($ctx, 'OrderItem') as $r) {
            $ctx->report->read('order_items');
            $order = $ctx->ids->get('Order', (string) $r->orderId);
            if ($order === null) {
                $ctx->report->skipped('order_items', "item {$r->id}: order not migrated");

                continue;
            }

            $item = $r->inventoryItemId === null ? null : $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId);
            $description = Normalize::str($r->customDescription);
            if ($r->inventoryItemId !== null && $item === null) {
                $description ??= '(inventory item no longer available)';
                $ctx->report->warn('orders', "item {$r->id}: inventory item not migrated; kept as custom line");
            }

            // Legacy also had unit_type 'units'; the rebuild only knows bottles|cases.
            $unit = SalesUnit::tryFrom((string) $r->unitType) ?? SalesUnit::Bottles;
            $units += $unit->value !== (string) $r->unitType ? 1 : 0;

            $this->put($ctx, 'OrderItem', (string) $r->id, 'order_items', [
                'order_id' => $order,
                'inventory_item_id' => $item,
                'quantity' => (int) $r->quantity,
                'unit_type' => $unit->value,
                'unit_price' => Normalize::minor($r->unitPrice),
                'unit_price_gross' => null, // not tracked by the legacy app
                'total' => Normalize::minor($r->total),
                'cost_per_unit' => Normalize::minorOrNull($r->costPerUnit),
                'custom_description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ctx->report->written('order_items');
        }

        if ($units > 0) {
            $ctx->report->warn('orders', "{$units} order lines had unit type 'units'; mapped to bottles");
        }
    }

    private function histories(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'OrderStatusHistory') as $r) {
            $ctx->report->read('order_status_histories');
            $order = $ctx->ids->get('Order', (string) $r->orderId);
            if ($order === null) {
                $ctx->report->skipped('order_status_histories', "history {$r->id}: order not migrated");

                continue;
            }
            $this->put($ctx, 'OrderStatusHistory', (string) $r->id, 'order_status_histories', [
                'order_id' => $order,
                'status' => (string) $r->status,
                'note' => Normalize::str($r->note),
                'changed_by_id' => $ctx->userId((string) $r->changedById, 'orders'),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('order_status_histories');
        }
    }

    private function notes(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'OrderNote') as $r) {
            $ctx->report->read('order_notes');
            $order = $ctx->ids->get('Order', (string) $r->orderId);
            if ($order === null) {
                $ctx->report->skipped('order_notes', "note {$r->id}: order not migrated");

                continue;
            }
            $this->put($ctx, 'OrderNote', (string) $r->id, 'order_notes', [
                'order_id' => $order,
                'content' => (string) $r->content,
                'author_id' => $ctx->userId((string) $r->authorId, 'orders'),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('order_notes');
        }
    }

    private function reactions(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'OrderNoteReaction') as $r) {
            $ctx->report->read('order_note_reactions');
            $note = $ctx->ids->get('OrderNote', (string) $r->noteId);
            $user = $ctx->ids->get('User', (string) $r->userId);
            if ($note === null || $user === null) {
                // A reaction can't be credited to the placeholder user; drop it.
                $ctx->report->skipped('order_note_reactions', "reaction {$r->id}: note/user not migrated");

                continue;
            }
            $this->put($ctx, 'OrderNoteReaction', (string) $r->id, 'order_note_reactions', [
                'order_note_id' => $note,
                'user_id' => $user,
                'emoji' => (string) $r->emoji,
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('order_note_reactions');
        }
    }

    private function consignment(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'ConsignmentReport') as $r) {
            $ctx->report->read('consignment_reports');
            $order = $ctx->ids->get('Order', (string) $r->orderId);
            if ($order === null) {
                $ctx->report->skipped('consignment_reports', "report {$r->id}: order not migrated");

                continue;
            }
            $this->put($ctx, 'ConsignmentReport', (string) $r->id, 'consignment_reports', [
                'order_id' => $order,
                'kind' => strtoupper((string) $r->kind),
                'date' => Normalize::date($r->date),
                'note' => Normalize::str($r->note),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'orders'),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('consignment_reports');
        }

        foreach ($this->rows($ctx, 'ConsignmentReportItem') as $r) {
            $ctx->report->read('consignment_report_items');
            $report = $ctx->ids->get('ConsignmentReport', (string) $r->reportId);
            $orderItem = $ctx->ids->get('OrderItem', (string) $r->orderItemId);
            if ($report === null || $orderItem === null) {
                $ctx->report->skipped('consignment_report_items', "report item {$r->id}: report/order item not migrated");

                continue;
            }
            $item = $r->inventoryItemId === null ? null : $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId);
            $this->put($ctx, 'ConsignmentReportItem', (string) $r->id, 'consignment_report_items', [
                'report_id' => $report,
                'order_item_id' => $orderItem,
                'inventory_item_id' => $item,
                'quantity' => (int) $r->quantity,
                'unit_price' => Normalize::minor($r->unitPrice),
                'total' => Normalize::minor($r->total),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ctx->report->written('consignment_report_items');
        }
    }

    /** Report (not fix) any order whose imported lines don't add up to its header total. */
    private function checkTotals(ImportContext $ctx): void
    {
        $tenantId = (string) $ctx->tenant->getKey();

        $bad = DB::table('orders as o')
            ->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->where('o.tenant_id', $tenantId)
            ->groupBy('o.id', 'o.order_number', 'o.total_amount')
            ->havingRaw('sum(i.total) <> o.total_amount')
            ->get(['o.order_number', 'o.total_amount', DB::raw('sum(i.total) as lines')]);

        foreach ($bad as $b) {
            $ctx->report->warn('orders', "order {$b->order_number}: header total {$b->total_amount} ≠ lines {$b->lines} (minor units)");
        }
    }
}
