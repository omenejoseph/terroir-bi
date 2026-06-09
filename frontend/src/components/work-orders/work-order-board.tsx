"use client";

import * as React from "react";
import { ArrowDown, ArrowUp, Trash2 } from "lucide-react";

import { useReorderWorkOrders, useUpdateWorkOrderStatus, useDeleteWorkOrder } from "@/hooks/use-work-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { TaskStatus, WorkOrder } from "@/lib/types";
import { TASK_STATUSES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Select } from "@/components/ui/select";
import { useConfirm } from "@/components/ui/confirm";
import { PRIORITY_VARIANT } from "@/components/work-orders/constants";

/** Kanban board grouped by status, with per-card status, reorder and delete. */
export function WorkOrderBoard({ workOrders }: { workOrders: WorkOrder[] }) {
  const { t } = useTranslation();
  const reorder = useReorderWorkOrders();

  function moveWithinColumn(task: WorkOrder, direction: -1 | 1) {
    const column = workOrders.filter((w) => w.status === task.status);
    const index = column.findIndex((w) => w.id === task.id);
    const swapWith = column[index + direction];
    if (!swapWith) return;
    const ids = workOrders.map((w) => w.id);
    const a = ids.indexOf(task.id);
    const b = ids.indexOf(swapWith.id);
    [ids[a], ids[b]] = [ids[b], ids[a]];
    reorder.mutate(ids);
  }

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      {TASK_STATUSES.map((status) => {
        const column = workOrders.filter((w) => w.status === status);
        return (
          <div key={status} role="group" aria-label={t(`tasks.columns.${status}`)} className="space-y-2">
            <div className="flex items-center justify-between px-1">
              <h2 className="text-sm font-semibold">{t(`tasks.columns.${status}`)}</h2>
              <span className="text-xs text-muted-foreground tabular-nums">{column.length}</span>
            </div>
            {column.length === 0 ? (
              <p className="px-1 text-xs text-muted-foreground">{t("tasks.empty")}</p>
            ) : (
              column.map((task, index) => (
                <WorkOrderCard
                  key={task.id}
                  task={task}
                  isFirst={index === 0}
                  isLast={index === column.length - 1}
                  onMove={moveWithinColumn}
                />
              ))
            )}
          </div>
        );
      })}
    </div>
  );
}

function WorkOrderCard({
  task,
  isFirst,
  isLast,
  onMove,
}: {
  task: WorkOrder;
  isFirst: boolean;
  isLast: boolean;
  onMove: (task: WorkOrder, direction: -1 | 1) => void;
}) {
  const { t } = useTranslation();
  const { date } = useFormatters();
  const confirm = useConfirm();
  const updateStatus = useUpdateWorkOrderStatus();
  const remove = useDeleteWorkOrder();

  const overdue =
    task.status !== "DONE" && task.due_date != null && new Date(task.due_date) < new Date();

  async function handleDelete() {
    const ok = await confirm({
      title: t("tasks.deleteTitle"),
      description: t("tasks.deleteBody", { title: task.title }),
      confirmLabel: t("tasks.delete"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(task.id);
  }

  return (
    <Card>
      <CardContent className="space-y-2 p-3">
        <div className="flex items-start justify-between gap-2">
          <p className="text-sm font-medium">{task.title}</p>
          <Badge variant={PRIORITY_VARIANT[task.priority]}>{t(`tasks.priority.${task.priority}`)}</Badge>
        </div>

        {task.due_date && (
          <p className={`text-xs ${overdue ? "font-medium text-destructive" : "text-muted-foreground"}`}>
            {overdue ? t("tasks.overdueBadge") : t("tasks.dueOn", { date: date(task.due_date) })}
          </p>
        )}

        {task.assignee && <p className="text-xs text-muted-foreground">{task.assignee.name}</p>}

        <div className="flex items-center justify-between gap-2 pt-1">
          <Select
            aria-label={t("tasks.setStatus")}
            value={task.status}
            onChange={(e) => updateStatus.mutate({ id: task.id, status: e.target.value as TaskStatus })}
            className="h-8 w-32 text-xs"
          >
            {TASK_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`tasks.status.${s}`)}
              </option>
            ))}
          </Select>
          <div className="flex items-center gap-1">
            <button
              type="button"
              aria-label={t("tasks.moveUp")}
              disabled={isFirst}
              onClick={() => onMove(task, -1)}
              className="text-muted-foreground hover:text-foreground disabled:opacity-30"
            >
              <ArrowUp className="size-4" />
            </button>
            <button
              type="button"
              aria-label={t("tasks.moveDown")}
              disabled={isLast}
              onClick={() => onMove(task, 1)}
              className="text-muted-foreground hover:text-foreground disabled:opacity-30"
            >
              <ArrowDown className="size-4" />
            </button>
            <button
              type="button"
              aria-label={t("tasks.delete")}
              onClick={handleDelete}
              className="text-muted-foreground hover:text-destructive"
            >
              <Trash2 className="size-4" />
            </button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
