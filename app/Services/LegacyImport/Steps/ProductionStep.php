<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\CellarActivitySkipReason;
use App\Enums\CellarActivityStatus;
use App\Enums\CellarActivityType;
use App\Enums\PlanUnit;
use App\Enums\ProductionPlanStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkOrderCategory;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\LegacyActivityPayload;
use App\Services\LegacyImport\Support\Normalize;

/** Boards, work orders, planned cellar activities (+ rounds) and production plans (+ rows). */
class ProductionStep extends AbstractStep
{
    public function name(): string
    {
        return 'production';
    }

    public function dependsOn(): array
    {
        return ['users', 'inventory', 'cellar'];
    }

    public function run(ImportContext $ctx): void
    {
        $this->boards($ctx);
        $this->workOrders($ctx);
        $this->activityRounds($ctx);
        $this->activities($ctx);
        $this->plans($ctx);
    }

    private function boards(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'Board') as $r) {
            $ctx->report->read('work_order_boards');
            // The rebuild's boards have no owner-privacy, member or list concept (status is the column).
            $this->put($ctx, 'Board', (string) $r->id, 'work_order_boards', [
                'name' => trim((string) $r->name),
                'created_by_id' => $ctx->userId($r->ownerId === null ? null : (string) $r->ownerId, 'production'),
                'sort_order' => (int) $r->sortOrder,
                'is_default' => Normalize::bool($r->isDefault),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('work_order_boards');
        }
    }

    private function workOrders(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'WorkOrder') as $r) {
            $ctx->report->read('work_orders');

            $status = TaskStatus::tryFrom((string) $r->status);
            if ($status === null) {
                $ctx->report->skipped('work_orders', "work order \"{$r->title}\": status '{$r->status}' has no equivalent (TODO/IN_PROGRESS/DONE only)");

                continue;
            }
            $priority = TaskPriority::tryFrom((string) $r->priority) ?? TaskPriority::Medium;
            $category = WorkOrderCategory::tryFrom((string) $r->category) ?? WorkOrderCategory::Other;

            $this->put($ctx, 'WorkOrder', (string) $r->id, 'work_orders', [
                'title' => $this->fit($ctx, 'production', "work order {$r->id} title", trim((string) $r->title)),
                'description' => Normalize::str($r->description),
                'category' => $category->value,
                'priority' => $priority->value,
                'status' => $status->value,
                'start_date' => Normalize::date($r->startDate),
                'due_date' => Normalize::date($r->dueDate),
                'completed_at' => Normalize::date($r->completedAt),
                'sort_order' => (int) $r->sortOrder,
                'assignee_id' => $r->assigneeId === null ? null : $ctx->ids->get('User', (string) $r->assigneeId),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'production'),
                'wine_lot_id' => $r->wineLotId === null ? null : $ctx->ids->get('WineLot', (string) $r->wineLotId),
                'vessel_id' => $r->vesselId === null ? null : $ctx->ids->get('Vessel', (string) $r->vesselId),
                'board_id' => $r->boardId === null ? null : $ctx->ids->get('Board', (string) $r->boardId),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('work_orders');
        }
    }

    /** Planned cellar activity rounds; their board card is a work order, hence this step. */
    private function activityRounds(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'CellarActivityRound') as $r) {
            $ctx->report->read('cellar_activity_rounds');
            $this->put($ctx, 'CellarActivityRound', (string) $r->id, 'cellar_activity_rounds', [
                'name' => $this->fit($ctx, 'production', "activity round {$r->id} name", trim((string) $r->name)),
                'work_order_id' => $r->workOrderId === null ? null : $ctx->ids->get('WorkOrder', (string) $r->workOrderId),
                'created_by_id' => $ctx->userId((string) $r->createdById, 'production'),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->createdAt),
            ]);
            $ctx->report->written('cellar_activity_rounds');
        }
    }

    private function activities(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'CellarActivity') as $r) {
            $ctx->report->read('cellar_activities');

            $type = CellarActivityType::tryFrom((string) $r->type);
            $status = CellarActivityStatus::tryFrom((string) $r->status);
            if ($type === null || $status === null) {
                $ctx->report->skipped('cellar_activities', "activity {$r->id}: unknown type/status '{$r->type}/{$r->status}'");

                continue;
            }

            $lot = $r->wineLotId === null ? null : $ctx->ids->get('WineLot', (string) $r->wineLotId);
            if ($type !== CellarActivityType::Vineyard && $lot === null) {
                $ctx->report->skipped('cellar_activities', "activity {$r->id}: lot not migrated");

                continue;
            }

            $decoded = json_decode((string) $r->payload, true);
            if (! is_array($decoded)) {
                $ctx->report->skipped('cellar_activities', "activity {$r->id}: payload is not valid JSON");

                continue;
            }

            $note = Normalize::str($r->note);
            $skipReason = null;
            if ($status === CellarActivityStatus::Skipped) {
                $legacyReason = Normalize::str($r->skipReason);
                $skipReason = $legacyReason === 'Nije potrebno' ? CellarActivitySkipReason::NotNeeded : CellarActivitySkipReason::Other;
                if ($legacyReason !== null && ! in_array($legacyReason, ['Nije potrebno', 'Drugo'], true)) {
                    $note = implode(' · ', array_filter([$note, $legacyReason]));
                }
            }

            $this->put($ctx, 'CellarActivity', (string) $r->id, 'cellar_activities', [
                'type' => $type->value,
                'status' => $status->value,
                'payload' => json_encode(LegacyActivityPayload::map($type, $decoded, $ctx), JSON_THROW_ON_ERROR),
                'planned_for' => Normalize::date($r->plannedFor)?->toDateString() ?? Normalize::date($r->createdAt)?->toDateString(),
                'sort_order' => (int) $r->sortOrder,
                'wine_lot_id' => $type === CellarActivityType::Vineyard ? null : $lot,
                'vessel_id' => $type === CellarActivityType::Vineyard || $r->vesselId === null ? null : $ctx->ids->get('Vessel', (string) $r->vesselId),
                'round_id' => $r->roundId === null ? null : $ctx->ids->get('CellarActivityRound', (string) $r->roundId),
                'assigned_to_id' => $r->assignedToId === null ? null : $ctx->ids->get('User', (string) $r->assignedToId),
                'note' => $note,
                'skip_reason' => $skipReason?->value,
                'deviated' => Normalize::bool($r->deviated),
                'applied_at' => $status === CellarActivityStatus::Done ? (Normalize::date($r->appliedAt) ?? Normalize::date($r->updatedAt)) : null,
                'skipped_at' => $status === CellarActivityStatus::Skipped ? Normalize::date($r->updatedAt) : null,
                'planned_by_id' => $ctx->userId((string) $r->plannedById, 'production'),
                'executed_by_id' => $r->executedById === null ? null : $ctx->ids->get('User', (string) $r->executedById),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('cellar_activities');
        }
    }

    private function plans(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'ProductionPlan') as $r) {
            $ctx->report->read('production_plans');
            $status = ProductionPlanStatus::tryFrom((string) $r->status);
            if ($status === null) {
                $ctx->report->skipped('production_plans', "plan {$r->name}: unknown status '{$r->status}'");

                continue;
            }
            $this->put($ctx, 'ProductionPlan', (string) $r->id, 'production_plans', [
                'created_by_id' => $ctx->userId((string) $r->createdById, 'production'),
                'name' => $this->fit($ctx, 'production', "plan {$r->id} name", trim((string) $r->name)),
                'status' => $status->value,
                'confirmed_at' => Normalize::date($r->confirmedAt),
                'notes' => Normalize::str($r->notes),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('production_plans');
        }

        foreach ($this->rows($ctx, 'ProductionPlanRow') as $r) {
            $ctx->report->read('production_plan_rows');
            $plan = $ctx->ids->get('ProductionPlan', (string) $r->planId);
            $base = $ctx->ids->get('InventoryItem', (string) $r->baseItemId);
            $unit = PlanUnit::tryFrom((string) $r->planUnit);
            if ($plan === null || $base === null || $unit === null) {
                $ctx->report->skipped('production_plan_rows', "plan row {$r->id}: plan/base item not migrated or unknown unit '{$r->planUnit}'");

                continue;
            }
            $this->put($ctx, 'ProductionPlanRow', (string) $r->id, 'production_plan_rows', [
                'plan_id' => $plan,
                'base_item_id' => $base,
                'created_item_id' => $r->createdItemId === null ? null : $ctx->ids->get('InventoryItem', (string) $r->createdItemId),
                'new_vintage' => Normalize::str($r->newVintage),
                'quantity' => Normalize::dec($r->quantity, 3),
                'plan_unit' => $unit->value,
                'sort_order' => (int) $r->sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ctx->report->written('production_plan_rows');
        }
    }
}
