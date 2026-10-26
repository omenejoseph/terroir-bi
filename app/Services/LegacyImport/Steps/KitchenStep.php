<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\KitchenCategory;
use App\Enums\KitchenCheckStatus;
use App\Enums\KitchenLocation;
use App\Enums\KitchenMovementType;
use App\Enums\KitchenUnit;
use App\Enums\KitchenWorkOrderStatus;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;

/**
 * The old app's kitchen (docs/21 §3): items, movements, stock-takes and prep tickets.
 *
 * Stock and movements are copied as they stand — the legacy stock is authoritative, and is not
 * recomputed from movements (the old app wrote no movement for opening stock, so the two need not
 * add up). A category, unit or location the rebuild does not know is mapped to a default and
 * reported. Lines whose parent did not come across are skipped and reported.
 *
 * Optional legacy columns are read with `?? null`, so a column the old schema does not have reads
 * as empty instead of failing the run.
 */
class KitchenStep extends AbstractStep
{
    public function name(): string
    {
        return 'kitchen';
    }

    public function dependsOn(): array
    {
        return ['users'];
    }

    public function run(ImportContext $ctx): void
    {
        $this->items($ctx);
        $this->movements($ctx);
        $this->checks($ctx);
        $this->workOrders($ctx);
    }

    private function items(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'KitchenItem') as $r) {
            $ctx->report->read('kitchen_items');
            $this->put($ctx, 'KitchenItem', (string) $r->id, 'kitchen_items', [
                'name' => $this->fit($ctx, 'kitchen_items', 'item name', trim((string) $r->name)) ?? '',
                'category' => $this->enum($ctx, KitchenCategory::class, $r->category ?? null, KitchenCategory::RawMaterial, "item {$r->id} category"),
                'group' => $this->fit($ctx, 'kitchen_items', 'group', Normalize::str($r->group ?? null)),
                'unit' => $this->enum($ctx, KitchenUnit::class, strtolower(trim((string) ($r->unit ?? ''))), KitchenUnit::Piece, "item {$r->id} unit"),
                'current_stock' => Normalize::dec($r->currentStock ?? 0) ?? '0.000',
                'min_stock' => Normalize::dec($r->minStock ?? null),
                'cost_per_unit' => Normalize::minorOrNull($r->costPerUnit ?? null),
                'supplier' => $this->fit($ctx, 'kitchen_items', 'supplier', Normalize::str($r->supplier ?? null)),
                'location' => $this->enum($ctx, KitchenLocation::class, strtolower(trim((string) ($r->location ?? ''))), KitchenLocation::Restaurant, "item {$r->id} location"),
                'is_active' => Normalize::bool($r->isActive ?? true),
                'sort_order' => (int) ($r->sortOrder ?? 0),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('kitchen_items');
        }
    }

    private function movements(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'KitchenMovement') as $r) {
            $ctx->report->read('kitchen_movements');
            $item = $ctx->ids->get('KitchenItem', (string) ($r->itemId ?? ''));
            if ($item === null) {
                $ctx->report->skipped('kitchen_movements', "movement {$r->id}: item not migrated");

                continue;
            }

            $this->put($ctx, 'KitchenMovement', (string) $r->id, 'kitchen_movements', [
                'item_id' => $item,
                'type' => $this->enum($ctx, KitchenMovementType::class, $r->type ?? null, KitchenMovementType::Adjustment, "movement {$r->id} type"),
                'quantity' => Normalize::dec($r->quantity ?? 0) ?? '0.000',
                'note' => Normalize::str($r->note ?? null),
                'user_id' => $ctx->userId(Normalize::str($r->userId ?? $r->createdById ?? null), 'kitchen'),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
            ]);
            $ctx->report->written('kitchen_movements');
        }
    }

    private function checks(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'KitchenCheck') as $r) {
            $ctx->report->read('kitchen_checks');
            $location = Normalize::str($r->location ?? null);

            $this->put($ctx, 'KitchenCheck', (string) $r->id, 'kitchen_checks', [
                'date' => (Normalize::date($r->date ?? null) ?? Normalize::date($r->createdAt ?? null) ?? now())->toDateString(),
                'status' => $this->enum($ctx, KitchenCheckStatus::class, $r->status ?? null, KitchenCheckStatus::Completed, "check {$r->id} status"),
                'location' => $location === null ? null : $this->enum($ctx, KitchenLocation::class, strtolower($location), KitchenLocation::Restaurant, "check {$r->id} location"),
                'note' => Normalize::str($r->note ?? null),
                'completed_at' => Normalize::date($r->completedAt ?? null),
                'user_id' => $ctx->userId(Normalize::str($r->userId ?? $r->createdById ?? null), 'kitchen'),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('kitchen_checks');
        }

        foreach ($this->rows($ctx, 'KitchenCheckItem') as $r) {
            $ctx->report->read('kitchen_check_items');
            $check = $ctx->ids->get('KitchenCheck', (string) ($r->checkId ?? ''));
            $item = $ctx->ids->get('KitchenItem', (string) ($r->itemId ?? ''));
            if ($check === null || $item === null) {
                $ctx->report->skipped('kitchen_check_items', "check line {$r->id}: ".($check === null ? 'stock-take' : 'item').' not migrated');

                continue;
            }

            $this->put($ctx, 'KitchenCheckItem', (string) $r->id, 'kitchen_check_items', [
                'check_id' => $check,
                'item_id' => $item,
                'system_stock' => Normalize::dec($r->systemStock ?? 0) ?? '0.000',
                'physical_count' => Normalize::dec($r->physicalCount ?? null),
                'difference' => Normalize::dec($r->difference ?? null),
            ]);
            $ctx->report->written('kitchen_check_items');
        }
    }

    private function workOrders(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'KitchenWorkOrder') as $r) {
            $ctx->report->read('kitchen_work_orders');
            $this->put($ctx, 'KitchenWorkOrder', (string) $r->id, 'kitchen_work_orders', [
                'title' => $this->fit($ctx, 'kitchen_work_orders', 'title', trim((string) ($r->title ?? ''))) ?? '',
                'date' => (Normalize::date($r->date ?? null) ?? Normalize::date($r->createdAt ?? null) ?? now())->toDateString(),
                'status' => $this->enum($ctx, KitchenWorkOrderStatus::class, $r->status ?? null, KitchenWorkOrderStatus::Planned, "prep ticket {$r->id} status"),
                'assigned_to' => $this->fit($ctx, 'kitchen_work_orders', 'assignee', Normalize::str($r->assignedTo ?? null)),
                'notes' => Normalize::str($r->notes ?? null),
                'completed_at' => Normalize::date($r->completedAt ?? null),
                'user_id' => $ctx->userId(Normalize::str($r->userId ?? $r->createdById ?? null), 'kitchen'),
                'created_at' => Normalize::date($r->createdAt ?? null) ?? now(),
                'updated_at' => Normalize::date($r->updatedAt ?? null) ?? now(),
            ]);
            $ctx->report->written('kitchen_work_orders');
        }

        foreach ($this->rows($ctx, 'KitchenWorkOrderItem') as $r) {
            $ctx->report->read('kitchen_work_order_items');
            $order = $ctx->ids->get('KitchenWorkOrder', (string) ($r->workOrderId ?? ''));
            if ($order === null) {
                $ctx->report->skipped('kitchen_work_order_items', "prep line {$r->id}: prep ticket not migrated");

                continue;
            }

            $legacyItem = Normalize::str($r->itemId ?? null);
            $item = $legacyItem === null ? null : $ctx->ids->get('KitchenItem', $legacyItem);

            $this->put($ctx, 'KitchenWorkOrderItem', (string) $r->id, 'kitchen_work_order_items', [
                'work_order_id' => $order,
                'item_id' => $item,
                // A line whose item is gone keeps a readable name rather than becoming blank.
                'custom_name' => Normalize::str($r->customName ?? null) ?? ($legacyItem !== null && $item === null ? __('(deleted item)') : null),
                'quantity' => Normalize::dec($r->quantity ?? 0) ?? '0.000',
                'unit' => Normalize::str($r->unit ?? null),
                'notes' => Normalize::str($r->notes ?? null),
                'sort_order' => (int) ($r->sortOrder ?? 0),
            ]);
            $ctx->report->written('kitchen_work_order_items');
        }
    }

    /**
     * The legacy value as a rebuild enum value, or the default (reported) when it is not one.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  T  $default
     */
    private function enum(ImportContext $ctx, string $enum, mixed $value, \BackedEnum $default, string $what): string
    {
        $case = is_string($value) || is_int($value) ? $enum::tryFrom(is_string($value) ? trim($value) : $value) : null;

        if ($case === null) {
            $ctx->report->warn('kitchen', "{$what}: '".(is_scalar($value) ? (string) $value : '')."' not recognised; imported as '{$default->value}'");

            return (string) $default->value;
        }

        return (string) $case->value;
    }
}
