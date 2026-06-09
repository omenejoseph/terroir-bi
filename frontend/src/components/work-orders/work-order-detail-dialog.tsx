"use client";

import * as React from "react";
import { Trash2 } from "lucide-react";

import { useDeleteWorkOrder, useUpdateWorkOrderStatus } from "@/hooks/use-work-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { TaskStatus, WorkOrder } from "@/lib/types";
import { TASK_STATUSES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";
import { PRIORITY_VARIANT } from "@/components/work-orders/constants";

/** Lightweight detail/edit popover for a single work order (status + delete). */
export function WorkOrderDetailDialog({
  workOrder,
  onClose,
}: {
  workOrder: WorkOrder | null;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { date } = useFormatters();
  const confirm = useConfirm();
  const updateStatus = useUpdateWorkOrderStatus();
  const remove = useDeleteWorkOrder();

  if (!workOrder) return null;

  async function handleDelete() {
    if (!workOrder) return;
    const ok = await confirm({
      title: t("tasks.deleteTitle"),
      description: t("tasks.deleteBody", { title: workOrder.title }),
      confirmLabel: t("tasks.delete"),
      tone: "danger",
    });
    if (!ok) return;
    await remove.mutateAsync(workOrder.id);
    onClose();
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()} title={workOrder.title}>
      <div className="space-y-4">
        <div className="flex items-center gap-2">
          <Badge variant={PRIORITY_VARIANT[workOrder.priority]}>
            {t(`tasks.priority.${workOrder.priority}`)}
          </Badge>
          {workOrder.assignee && (
            <span className="text-sm text-muted-foreground">{workOrder.assignee.name}</span>
          )}
        </div>

        {workOrder.description && (
          <p className="text-sm text-muted-foreground">{workOrder.description}</p>
        )}

        <dl className="grid grid-cols-2 gap-3 text-sm">
          <div>
            <dt className="text-xs text-muted-foreground">{t("tasks.detail.start")}</dt>
            <dd>{workOrder.start_date ? date(workOrder.start_date) : "—"}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">{t("tasks.detail.due")}</dt>
            <dd>{workOrder.due_date ? date(workOrder.due_date) : "—"}</dd>
          </div>
        </dl>

        <div className="space-y-1">
          <Label htmlFor="wo_detail_status">{t("tasks.detail.status")}</Label>
          <Select
            id="wo_detail_status"
            value={workOrder.status}
            onChange={(e) =>
              updateStatus.mutate({ id: workOrder.id, status: e.target.value as TaskStatus })
            }
          >
            {TASK_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`tasks.status.${s}`)}
              </option>
            ))}
          </Select>
        </div>

        <div className="flex justify-between border-t border-border pt-4">
          <Button
            type="button"
            variant="ghost"
            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
            onClick={handleDelete}
            disabled={remove.isPending}
          >
            {remove.isPending ? <Spinner /> : <Trash2 className="size-4" />}
            {t("tasks.delete")}
          </Button>
          <Button type="button" variant="outline" onClick={onClose}>
            {t("common.confirm.cancel")}
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
