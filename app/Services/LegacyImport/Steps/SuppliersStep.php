<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;

/** Supplier (+ price list) → suppliers, supplier_price_items. */
class SuppliersStep extends AbstractStep
{
    public function name(): string
    {
        return 'suppliers';
    }

    public function dependsOn(): array
    {
        return ['inventory'];
    }

    public function run(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'Supplier') as $r) {
            $ctx->report->read('suppliers');

            $this->put($ctx, 'Supplier', (string) $r->id, 'suppliers', [
                'company_name' => trim((string) $r->companyName),
                'contact_name' => Normalize::str($r->contactName),
                'email' => Normalize::str($r->email),
                'phone' => Normalize::str($r->phone),
                'address' => Normalize::str($r->address),
                'city' => Normalize::str($r->city),
                'country' => Normalize::str($r->country),
                'tax_id' => Normalize::str($r->taxId),
                'bank_account' => Normalize::str($r->bankAccount),
                'payment_terms' => Normalize::str($r->paymentTerms),
                'notes' => Normalize::str($r->notes),
                'is_active' => Normalize::bool($r->isActive),
                'exclude_from_stats' => Normalize::bool($r->excludeFromStats),
                'is_cooperant' => Normalize::bool($r->isCooperant),
                // The rebuild has no separate "portal enabled" flag: a token means enabled.
                'portal_token' => Normalize::bool($r->portalEnabled) ? Normalize::str($r->portalToken) : null,
                'is_ai_generated' => false,
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('suppliers');
        }

        $this->priceItems($ctx);
    }

    /**
     * (supplier, description) is unique in the rebuild, and trimming whitespace can collapse
     * rows that were distinct in the legacy data; keep the most recently updated of each group.
     *
     * @return array<string, true> legacy ids to skip
     */
    private function losingDuplicates(ImportContext $ctx): array
    {
        $groups = [];
        foreach ($this->rows($ctx, 'SupplierPriceItem') as $r) {
            $groups[$r->supplierId.'|'.trim((string) $r->description)][] = $r;
        }

        $lose = [];
        foreach ($groups as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            usort($rows, fn ($a, $b) => strcmp((string) $b->lastUpdated, (string) $a->lastUpdated));
            foreach (array_slice($rows, 1) as $dup) {
                $lose[(string) $dup->id] = true;
            }
        }

        return $lose;
    }

    private function priceItems(ImportContext $ctx): void
    {
        $lose = $this->losingDuplicates($ctx);

        foreach ($this->rows($ctx, 'SupplierPriceItem') as $r) {
            $ctx->report->read('supplier_price_items');
            if (isset($lose[(string) $r->id])) {
                $ctx->report->skipped('supplier_price_items', 'duplicate of a newer price item after trimming: "'.trim((string) $r->description).'"');

                continue;
            }
            $supplier = $ctx->ids->get('Supplier', (string) $r->supplierId);
            if ($supplier === null) {
                $ctx->report->skipped('supplier_price_items', "price item {$r->id}: supplier not migrated");

                continue;
            }

            $item = $r->inventoryItemId === null ? null : $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId);
            if ($r->inventoryItemId !== null && $item === null) {
                $ctx->report->warn('supplier_price_items', "price item {$r->id}: linked inventory item not migrated; link dropped");
            }

            $this->put($ctx, 'SupplierPriceItem', (string) $r->id, 'supplier_price_items', [
                'supplier_id' => $supplier,
                'inventory_item_id' => $item,
                'description' => trim((string) $r->description),
                'unit_price' => Normalize::minor($r->unitPrice),
                'unit' => Normalize::str($r->unit),
                'notes' => Normalize::str($r->notes),
                'last_updated' => Normalize::date($r->lastUpdated),
                'created_at' => Normalize::date($r->lastUpdated),
                'updated_at' => Normalize::date($r->lastUpdated),
            ]);
            $ctx->report->written('supplier_price_items');
        }
    }
}
