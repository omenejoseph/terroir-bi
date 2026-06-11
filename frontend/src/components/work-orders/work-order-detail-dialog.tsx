"use client";

import * as React from "react";
import { Trash2 } from "lucide-react";

import {
  useDeleteWorkOrder,
  useUpdateWorkOrder,
  useUpdateWorkOrderStatus,
} from "@/hooks/use-work-orders";
import { useAuth } from "@/lib/auth/context";
import { useMembers } from "@/hooks/use-team";
import { useTranslation } from "@/i18n/context";
import type { TaskStatus, WorkOrder } from "@/lib/types";
import { TASK_STATUSES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
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
  const confirm = useConfirm();
  const updateStatus = useUpdateWorkOrderStatus();
  const update = useUpdateWorkOrder();
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
        </div>

        {workOrder.description && (
          <p className="text-sm text-muted-foreground">{workOrder.description}</p>
        )}

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1">
            <Label htmlFor="wo_detail_start">{t("tasks.detail.start")}</Label>
            <Input
              id="wo_detail_start"
              type="date"
              value={workOrder.start_date ? workOrder.start_date.slice(0, 10) : ""}
              onChange={(e) =>
                update.mutate({ id: workOrder.id, input: { start_date: e.target.value || null } })
              }
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor="wo_detail_due">{t("tasks.detail.due")}</Label>
            <Input
              id="wo_detail_due"
              type="date"
              value={workOrder.due_date ? workOrder.due_date.slice(0, 10) : ""}
              onChange={(e) =>
                update.mutate({ id: workOrder.id, input: { due_date: e.target.value || null } })
              }
            />
          </div>
        </div>

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

        <AssigneeField workOrder={workOrder} />

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

/**
 * Reassign the work order. Editable for members-viewers, read-only otherwise.
 * The members list is only fetched (via the inner select) while the dialog is
 * open and the user can view members.
 */
function AssigneeField({ workOrder }: { workOrder: WorkOrder }) {
  const { t } = useTranslation();
  const { can } = useAuth();

  if (!can("members.view")) {
    return (
      <div className="space-y-1">
        <Label>{t("tasks.detail.assignee")}</Label>
        <p className="text-sm text-muted-foreground">
          {workOrder.assignee?.name ?? t("tasks.create.unassigned")}
        </p>
      </div>
    );
  }

  return <AssigneeSelect workOrder={workOrder} />;
}

function AssigneeSelect({ workOrder }: { workOrder: WorkOrder }) {
  const { t } = useTranslation();
  const update = useUpdateWorkOrder();
  const membersQ = useMembers();

  return (
    <div className="space-y-1">
      <Label htmlFor="wo_detail_assignee">{t("tasks.detail.assignee")}</Label>
      <Select
        id="wo_detail_assignee"
        value={workOrder.assignee?.id ?? ""}
        onChange={(e) =>
          update.mutate({ id: workOrder.id, input: { assignee_id: e.target.value || null } })
        }
      >
        <option value="">{t("tasks.create.unassigned")}</option>
        {(membersQ.data ?? []).map((m) => (
          <option key={m.user_id} value={m.user_id}>
            {m.name}
          </option>
        ))}
      </Select>
    </div>
  );
}
