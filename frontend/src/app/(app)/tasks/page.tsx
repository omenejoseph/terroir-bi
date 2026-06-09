"use client";

import * as React from "react";
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useMembers } from "@/hooks/use-team";
import {
  useCreateWorkOrder,
  useDeleteWorkOrder,
  useReorderWorkOrders,
  useUpdateWorkOrderStatus,
  useWorkOrders,
  useWorkOrderStats,
} from "@/hooks/use-work-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { TaskPriority, TaskStatus, WorkOrder } from "@/lib/types";
import { TASK_PRIORITIES, TASK_STATUSES } from "@/lib/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { useConfirm } from "@/components/ui/confirm";

const PRIORITY_VARIANT: Record<TaskPriority, "secondary" | "default" | "outline"> = {
  LOW: "secondary",
  MEDIUM: "outline",
  HIGH: "default",
};

export default function TasksPage() {
  const { t } = useTranslation();
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const { data, isLoading, isError, error } = useWorkOrders(debounced ? { search: debounced } : {});
  const statsQ = useWorkOrderStats();
  const reorder = useReorderWorkOrders();

  const tasks = React.useMemo(
    () => [...(data ?? [])].sort((a, b) => a.sort_order - b.sort_order),
    [data],
  );

  function moveWithinColumn(task: WorkOrder, direction: -1 | 1) {
    const column = tasks.filter((t) => t.status === task.status);
    const index = column.findIndex((t) => t.id === task.id);
    const swapWith = column[index + direction];
    if (!swapWith) return;
    // Build the full id list with the two tasks swapped in global order.
    const ids = tasks.map((t) => t.id);
    const a = ids.indexOf(task.id);
    const b = ids.indexOf(swapWith.id);
    [ids[a], ids[b]] = [ids[b], ids[a]];
    reorder.mutate(ids);
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("tasks.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("tasks.subtitle")}</p>
        </div>
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t("tasks.search")}
          className="sm:max-w-xs"
        />
      </header>

      {/* Stats strip */}
      {statsQ.data && (
        <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
          <StatTile label={t("tasks.stats.todo")} value={statsQ.data.todo} />
          <StatTile label={t("tasks.stats.inProgress")} value={statsQ.data.in_progress} />
          <StatTile label={t("tasks.stats.done")} value={statsQ.data.done} />
          <StatTile label={t("tasks.stats.overdue")} value={statsQ.data.overdue} accent />
        </div>
      )}

      <QuickCreateRow />

      {isLoading && (
        <div className="flex items-center justify-center py-16">
          <Spinner className="size-6 text-muted-foreground" />
        </div>
      )}

      {isError && (
        <Card>
          <CardContent className="py-8 text-center text-sm text-destructive">
            {error instanceof ApiError && error.status === 403
              ? t("tasks.errorForbidden")
              : t("tasks.errorGeneric")}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && (
        <div className="grid gap-4 lg:grid-cols-3">
          {TASK_STATUSES.map((status) => {
            const column = tasks.filter((t) => t.status === status);
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
                    <TaskCard
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
      )}
    </div>
  );
}

function StatTile({ label, value, accent }: { label: string; value: number; accent?: boolean }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className={`mt-1 text-2xl font-semibold tabular-nums ${accent ? "text-destructive" : ""}`}>
          {value}
        </p>
      </CardContent>
    </Card>
  );
}

function QuickCreateRow() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const create = useCreateWorkOrder();
  const canViewMembers = can("members.view");

  const [title, setTitle] = React.useState("");
  const [dueDate, setDueDate] = React.useState("");
  const [priority, setPriority] = React.useState<TaskPriority>("MEDIUM");
  const [assigneeId, setAssigneeId] = React.useState("");

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    if (!title.trim()) return;
    await create.mutateAsync({
      title: title.trim(),
      priority,
      ...(dueDate ? { due_date: dueDate } : {}),
      ...(assigneeId ? { assignee_id: assigneeId } : {}),
    });
    setTitle("");
    setDueDate("");
    setPriority("MEDIUM");
    setAssigneeId("");
  }

  return (
    <Card>
      <CardContent className="pt-6">
        <form
          onSubmit={handleSubmit}
          className="grid grid-cols-1 gap-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end"
        >
          <div className="space-y-1">
            <Label htmlFor="task_title" className="text-xs">
              {t("tasks.create.titleLabel")}
            </Label>
            <Input id="task_title" value={title} onChange={(e) => setTitle(e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="task_due" className="text-xs">
              {t("tasks.create.dueDate")}
            </Label>
            <Input id="task_due" type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="task_priority" className="text-xs">
              {t("tasks.create.priority")}
            </Label>
            <Select id="task_priority" value={priority} onChange={(e) => setPriority(e.target.value as TaskPriority)}>
              {TASK_PRIORITIES.map((p) => (
                <option key={p} value={p}>
                  {t(`tasks.priority.${p}`)}
                </option>
              ))}
            </Select>
          </div>
          <Button type="submit" disabled={create.isPending || !title.trim()}>
            {create.isPending ? <Spinner /> : <Plus className="size-4" />}
            {t("tasks.create.submit")}
          </Button>
          {canViewMembers && <AssigneeField value={assigneeId} onChange={setAssigneeId} />}
        </form>
      </CardContent>
    </Card>
  );
}

function AssigneeField({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const { t } = useTranslation();
  const membersQ = useMembers();
  const members = membersQ.data ?? [];

  return (
    <div className="space-y-1 sm:col-span-4">
      <Label htmlFor="task_assignee" className="text-xs">
        {t("tasks.create.assignee")}
      </Label>
      <Select id="task_assignee" value={value} onChange={(e) => onChange(e.target.value)} className="sm:max-w-xs">
        <option value="">{t("tasks.create.unassigned")}</option>
        {members.map((m) => (
          <option key={m.user_id} value={m.user_id}>
            {m.name}
          </option>
        ))}
      </Select>
    </div>
  );
}

function TaskCard({
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
