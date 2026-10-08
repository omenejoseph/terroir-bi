<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\InventoryCategory;
use App\Enums\SalesUnit;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;
use Illuminate\Support\Facades\DB;

/** InventoryItem (+ images, recipe lines) → inventory_items, inventory_images, recipe_items. */
class InventoryStep extends AbstractStep
{
    /** Seed/demo rows that ended up in production (no orders; never real stock). */
    public const SEED_SKUS = ['FP-REDWINE-001'];

    private const MIME = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];

    public function name(): string
    {
        return 'inventory';
    }

    public function run(ImportContext $ctx): void
    {
        $this->items($ctx);
        $this->images($ctx);
        $this->recipes($ctx);
    }

    private function items(ImportContext $ctx): void
    {
        $self = [];

        foreach ($this->rows($ctx, 'InventoryItem') as $r) {
            $ctx->report->read('inventory');

            if (in_array($r->sku, self::SEED_SKUS, true)) {
                $ctx->report->skipped('inventory', "seed item {$r->sku} ({$r->name}) not migrated");

                continue;
            }

            $category = InventoryCategory::tryFrom((string) $r->category);
            if ($category === null) {
                $ctx->report->skipped('inventory', "item {$r->sku}: unknown category '{$r->category}'");

                continue;
            }

            // Legacy sales_unit also held 'units'/NULL; the rebuild only knows bottles|cases.
            $salesUnit = SalesUnit::tryFrom((string) $r->salesUnit) ?? SalesUnit::Bottles;

            $id = $this->put($ctx, 'InventoryItem', (string) $r->id, 'inventory_items', [
                'name' => trim((string) $r->name),
                'sku' => trim((string) $r->sku),
                'description' => Normalize::str($r->description),
                'category' => $category->value,
                'group' => Normalize::str($r->group),
                'subcategory' => Normalize::str($r->subcategory),
                'vintage' => Normalize::str($r->vintage),
                'unit_size' => Normalize::str($r->unitSize),
                'unit' => trim((string) $r->unit),
                'sales_unit' => $salesUnit->value,
                'current_stock' => Normalize::dec($r->currentStock, 3) ?? '0.000',
                'min_stock' => Normalize::dec($r->minStock, 3),
                'is_active' => Normalize::bool($r->isActive),
                'sort_order' => (int) $r->sortOrder,
                // Prices were per bottle in the legacy app too (see revert_case_item_prices_to_per_bottle).
                'default_price' => Normalize::minorOrNull($r->defaultPrice),
                'bottles_per_case' => (int) $r->bottlesPerCase,
                'pack_size' => (int) $r->packSize,
                'is_for_sale' => Normalize::bool($r->isForSale),
                'hide_from_portal' => Normalize::bool($r->hideFromPortal),
                'is_auto_created' => Normalize::bool($r->isAutoCreated),
                'auto_created_at' => Normalize::date($r->autoCreatedAt),
                'cost_per_unit' => Normalize::minorOrNull($r->costPerUnit),
                'is_ai_generated' => false,
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('inventory');

            if ($r->baseProductId !== null) {
                $self[$id] = (string) $r->baseProductId;
            }
        }

        // Second pass: vintage-sibling self links (needs every item mapped first).
        foreach ($self as $id => $legacyBase) {
            $base = $ctx->ids->get('InventoryItem', $legacyBase);
            if ($base === null) {
                $ctx->report->warn('inventory', "base product {$legacyBase} for item {$id} not migrated; link dropped");

                continue;
            }
            DB::table('inventory_items')->where('id', $id)->update(['base_product_id' => $base]);
        }
    }

    private function images(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'InventoryImage') as $r) {
            $ctx->report->read('inventory_images');
            $itemId = $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId);
            if ($itemId === null) {
                $ctx->report->skipped('inventory_images', "image {$r->id}: item not migrated");

                continue;
            }

            $path = ltrim((string) (parse_url((string) $r->url, PHP_URL_PATH) ?: $r->url), '/');
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            $this->put($ctx, 'InventoryImage', (string) $r->id, 'inventory_images', [
                'inventory_item_id' => $itemId,
                // Final key under the tenant namespace; bytes arrive via the media-copy command.
                'object_key' => 'tenants/'.$ctx->tenant->getKey().'/'.$path,
                'content_type' => self::MIME[$ext] ?? 'application/octet-stream',
                'size_bytes' => 0,
                'alt' => Normalize::str($r->alt),
                'sort_order' => (int) $r->sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ctx->report->written('inventory_images');
        }
    }

    private function recipes(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'RecipeItem') as $r) {
            $ctx->report->read('recipes');
            $output = $ctx->ids->get('InventoryItem', (string) $r->outputId);
            $input = $r->inputId === null ? null : $ctx->ids->get('InventoryItem', (string) $r->inputId);

            if ($output === null || ($r->inputId !== null && $input === null)) {
                $ctx->report->skipped('recipes', "recipe line {$r->id}: output/input item not migrated");

                continue;
            }

            $this->put($ctx, 'RecipeItem', (string) $r->id, 'recipe_items', [
                'output_id' => $output,
                'input_id' => $input,
                'quantity' => Normalize::dec($r->quantity, 3),
                'custom_name' => Normalize::str($r->customName),
                'custom_unit' => Normalize::str($r->customUnit),
                'custom_cost' => Normalize::minorOrNull($r->customCost),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ctx->report->written('recipes');
        }
    }
}
