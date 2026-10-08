<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\CostStatus;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Mapping;
use App\Services\LegacyImport\Support\Normalize;
use App\Services\LegacyImport\Support\Sql;
use Illuminate\Support\Facades\DB;

/** Cost (+ lines) → costs, cost_items. Bank-transaction and e-invoice links have no counterpart and are dropped. */
class CostsStep extends AbstractStep
{
    public function name(): string
    {
        return 'costs';
    }

    public function dependsOn(): array
    {
        return ['users', 'inventory', 'suppliers'];
    }

    public function run(ImportContext $ctx): void
    {
        $this->costs($ctx);
        $this->items($ctx);
        $this->checkTotals($ctx);
    }

    private function costs(ImportContext $ctx): void
    {
        $foreign = 0;
        // The old app called a cost "invoiced" when a supplier e-invoice was linked to it; that is
        // what its VAT and spend summaries counted, so the link becomes the is_invoice flag.
        $invoiced = $ctx->legacy->table('EInvoice')->whereNotNull('costId')->pluck('costId')->flip();

        foreach ($this->rows($ctx, 'Cost') as $r) {
            $ctx->report->read('costs');

            $status = CostStatus::tryFrom((string) $r->status);
            if ($status === null) {
                $ctx->report->skipped('costs', "cost {$r->id} ({$r->reference}): unknown status '{$r->status}'");

                continue;
            }
            $foreign += strtoupper((string) $r->currency) !== 'EUR' ? 1 : 0;

            $supplier = $r->supplierId === null ? null : $ctx->ids->get('Supplier', (string) $r->supplierId);
            if ($r->supplierId !== null && $supplier === null) {
                $ctx->report->warn('costs', "cost {$r->id}: supplier not migrated; link dropped");
            }

            $this->put($ctx, 'Cost', (string) $r->id, 'costs', [
                'date' => Normalize::date($r->date),
                'total_amount' => Normalize::minor($r->totalAmount),
                'vat_amount' => Normalize::minorOrNull($r->vatAmount),
                // Free text on purpose: operators add their own; CostCategory only lists the ones dashboards match.
                'category' => $this->fit($ctx, 'costs', "cost {$r->id} category", trim((string) $r->category)),
                'description' => Normalize::str($r->description),
                'reference' => $this->fit($ctx, 'costs', "cost {$r->id} reference", Normalize::str($r->reference)),
                'status' => $status->value,
                'payment_method' => Mapping::paymentMethod($r->paymentMethod),
                'notes' => Normalize::str($r->notes),
                'paid_at' => Normalize::date($r->paidAt),
                'due_date' => Normalize::date($r->dueDate),
                'supplier_id' => $supplier,
                'created_by_id' => $ctx->userId((string) $r->createdById, 'costs'),
                'is_invoice' => isset($invoiced[(string) $r->id]),
                'is_ai_generated' => false,
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('costs');
        }

        if ($foreign > 0) {
            $ctx->report->warn('costs', "{$foreign} costs are not in EUR; amounts imported as-is in the tenant currency");
        }
    }

    private function items(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'CostItem') as $r) {
            $ctx->report->read('cost_items');
            $cost = $ctx->ids->get('Cost', (string) $r->costId);
            if ($cost === null) {
                $ctx->report->skipped('cost_items', "cost item {$r->id}: cost not migrated");

                continue;
            }
            $item = $r->inventoryItemId === null ? null : $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId);

            $this->put($ctx, 'CostItem', (string) $r->id, 'cost_items', [
                'cost_id' => $cost,
                'inventory_item_id' => $item,
                'description' => trim((string) $r->description),
                'quantity' => Normalize::dec($r->quantity, 3),
                'unit_price' => Normalize::minor($r->unitPrice),
                'total' => Normalize::minor($r->total),
                'category' => $this->fit($ctx, 'costs', "cost item {$r->id} category", Normalize::str($r->category)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ctx->report->written('cost_items');
        }
    }

    /** Informational: line totals often differ from the header (VAT-inclusive vs exclusive). */
    private function checkTotals(ImportContext $ctx): void
    {
        $n = Sql::countGroups(DB::table('costs as c')
            ->join('cost_items as i', 'i.cost_id', '=', 'c.id')
            ->where('c.tenant_id', (string) $ctx->tenant->getKey())
            ->groupBy('c.id', 'c.total_amount')
            ->havingRaw('sum(i.total) <> c.total_amount')
            ->select('c.id'));

        if ($n > 0) {
            $ctx->report->warn('costs', "{$n} costs have line totals that differ from the header total (likely VAT-inclusive vs exclusive); header kept");
        }
    }
}
