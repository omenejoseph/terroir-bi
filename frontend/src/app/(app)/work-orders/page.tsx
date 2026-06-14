"use client";

import * as React from "react";
import { ChevronLeft, ChevronRight, Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useMembers } from "@/hooks/use-team";
import { useCreateWorkOrder, useWorkOrders } from "@/hooks/use-work-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { TaskPriority, TaskStatus, WorkOrder, WorkOrderCategory } from "@/lib/types";
import { TASK_PRIORITIES, TASK_STATUSES, WORK_ORDER_CATEGORIES } from "@/lib/types";
import { addDays, addMonths, endOfWeek, startOfDay, startOfWeek } from "@/lib/calendar";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { WorkOrderBoard } from "@/components/work-orders/work-order-board";
import { WorkOrderCalendar, type Granularity } from "@/components/work-orders/work-order-calendar";
import { WorkOrderDetailDialog } from "@/components/work-orders/work-order-detail-dialog";

type View = "board" | Granularity;

/** Local YYYY-MM-DD for prefilling a quick-add from a calendar day. */
function isoDate(d: Date): string {
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}

export default function WorkOrdersPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const { date, monthYear } = useFormatters();
  const canViewMembers = can("members.view");

  const [view, setView] = React.useState<View>("day");
  const [anchor, setAnchor] = React.useState<Date>(() => startOfDay(new Date()));
  const [selected, setSelected] = React.useState<WorkOrder | null>(null);
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [creating, setCreating] = React.useState(false);
  const [createDate, setCreateDate] = React.useState<string | null>(null);

  // Filters (applied client-side so the option lists stay complete).
  const [filterStatus, setFilterStatus] = React.useState<TaskStatus | "">("");
  const [filterAssignee, setFilterAssignee] = React.useState("");
  const [filterCategory, setFilterCategory] = React.useState("");

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const { data, isLoading, isError, error } = useWorkOrders(debounced ? { search: debounced } : {});
  const membersQ = useMembers();
  const members = membersQ.data ?? [];

  const workOrders = React.useMemo(
    () => [...(data ?? [])].sort((a, b) => a.sort_order - b.sort_order),
    [data],
  );

  const filtered = React.useMemo(
    () =>
      workOrders.filter(
        (w) =>
          (!filterStatus || w.status === filterStatus) &&
          (!filterAssignee || w.assignee?.id === filterAssignee) &&
          (!filterCategory || w.category === filterCategory),
      ),
    [workOrders, filterStatus, filterAssignee, filterCategory],
  );

  // Keep the open dialog's work order in sync with refreshed list data.
  const selectedLive = selected ? workOrders.find((w) => w.id === selected.id) ?? null : null;

  const viewTabs = [
    { value: "board", label: t("tasks.views.board") },
    { value: "day", label: t("tasks.views.day") },
    { value: "week", label: t("tasks.views.week") },
    { value: "month", label: t("tasks.views.month") },
    { value: "quarter", label: t("tasks.views.quarter") },
  ];

  function shift(direction: -1 | 1) {
    setAnchor((current) => {
      switch (view) {
        case "day":
          return addDays(current, direction);
        case "week":
          return addDays(current, direction * 7);
        case "month":
          return addMonths(current, direction);
        case "quarter":
          return addMonths(current, direction * 3);
        default:
          return current;
      }
    });
  }

  function periodLabel(): string {
    switch (view) {
      case "day":
        return date(anchor);
      case "week":
        return `${date(startOfWeek(anchor))} – ${date(endOfWeek(anchor))}`;
      case "month":
        return monthYear(anchor);
      case "quarter": {
        const q = Math.floor(anchor.getMonth() / 3) + 1;
        return `Q${q} ${anchor.getFullYear()}`;
      }
      default:
        return "";
    }
  }

  function addForDate(day: Date) {
    setCreateDate(isoDate(day));
    setCreating(true);
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("tasks.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("tasks.subtitle")}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("tasks.search")}
            className="w-full sm:w-auto sm:max-w-xs"
          />
          <Button
            onClick={() => {
              setCreateDate(null);
              setCreating((c) => !c);
            }}
            className="shrink-0"
          >
            <Plus className="size-4" />
            {t("tasks.create.open")}
          </Button>
        </div>
      </header>

      {/* Filters: status, assignee, category. */}
      <div className="flex flex-wrap items-center gap-2">
        <Select
          aria-label={t("tasks.filters.status")}
          value={filterStatus}
          onChange={(e) => setFilterStatus(e.target.value as TaskStatus | "")}
          className="h-9 w-auto"
        >
          <option value="">{t("tasks.filters.allStatuses")}</option>
          {TASK_STATUSES.map((s) => (
            <option key={s} value={s}>
              {t(`tasks.status.${s}`)}
            </option>
          ))}
        </Select>
        {canViewMembers && (
          <Select
            aria-label={t("tasks.filters.assignee")}
            value={filterAssignee}
            onChange={(e) => setFilterAssignee(e.target.value)}
            className="h-9 w-auto"
          >
            <option value="">{t("tasks.filters.allAssignees")}</option>
            {members.map((m) => (
              <option key={m.user_id} value={m.user_id}>
                {m.name}
              </option>
            ))}
          </Select>
        )}
        <Select
          aria-label={t("tasks.filters.category")}
          value={filterCategory}
          onChange={(e) => setFilterCategory(e.target.value)}
          className="h-9 w-auto"
        >
          <option value="">{t("tasks.filters.allCategories")}</option>
          {WORK_ORDER_CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {t(`tasks.category.${c}`)}
            </option>
          ))}
        </Select>
      </div>

      <Dialog
        open={creating}
        onOpenChange={(o) => {
          if (!o) {
            setCreating(false);
            setCreateDate(null);
          }
        }}
        title={t("tasks.create.title")}
      >
        <CreateWorkOrderForm
          key={createDate ?? "new"}
          initialDate={createDate ?? ""}
          onDone={() => {
            setCreating(false);
            setCreateDate(null);
          }}
        />
      </Dialog>

      {/* View switcher + date navigator */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <Tabs tabs={viewTabs} value={view} onChange={(v) => setView(v as View)} />
        {view !== "board" && (
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => setAnchor(startOfDay(new Date()))}>
              {t("tasks.nav.today")}
            </Button>
            <div className="flex items-center gap-1">
              <button
                type="button"
                aria-label={t("tasks.nav.prev")}
                onClick={() => shift(-1)}
                className="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
              >
                <ChevronLeft className="size-4" />
              </button>
              <button
                type="button"
                aria-label={t("tasks.nav.next")}
                onClick={() => shift(1)}
                className="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
              >
                <ChevronRight className="size-4" />
              </button>
            </div>
            <span className="text-sm font-medium tabular-nums">{periodLabel()}</span>
          </div>
        )}
      </div>

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

      {!isLoading && !isError && view === "board" && (
        <WorkOrderBoard workOrders={filtered} allWorkOrders={workOrders} />
      )}

      {!isLoading && !isError && view !== "board" && (
        <WorkOrderCalendar
          workOrders={filtered}
          granularity={view}
          anchor={anchor}
          onSelect={setSelected}
          onPickDate={(d) => {
            setAnchor(d);
            setView("day");
          }}
          onAddForDate={addForDate}
        />
      )}

      <WorkOrderDetailDialog workOrder={selectedLive} onClose={() => setSelected(null)} />
    </div>
  );
}

/** The "new work order" form, shown inside the create modal. */
function CreateWorkOrderForm({ initialDate, onDone }: { initialDate: string; onDone: () => void }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const create = useCreateWorkOrder();
  const canViewMembers = can("members.view");
  const membersQ = useMembers();
  const members = membersQ.data ?? [];

  const [title, setTitle] = React.useState("");
  const [dueDate, setDueDate] = React.useState(initialDate);
  const [category, setCategory] = React.useState<WorkOrderCategory | "">("");
  const [priority, setPriority] = React.useState<TaskPriority>("MEDIUM");
  const [assigneeId, setAssigneeId] = React.useState("");

  async function handleSubmit(event: React.SyntheticEvent) {
    event.preventDefault();
    if (!title.trim()) return;
    await create.mutateAsync({
      title: title.trim(),
      priority,
      ...(dueDate ? { due_date: dueDate } : {}),
      ...(category ? { category } : {}),
      ...(assigneeId ? { assignee_id: assigneeId } : {}),
    });
    onDone();
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="space-y-1.5">
        <Label htmlFor="task_title">{t("tasks.create.titleLabel")}</Label>
        {/* eslint-disable-next-line jsx-a11y/no-autofocus */}
        <Input id="task_title" value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="task_due">{t("tasks.create.dueDate")}</Label>
          <Input id="task_due" type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="task_category">{t("tasks.create.category")}</Label>
          <Select
            id="task_category"
            value={category}
            onChange={(e) => setCategory(e.target.value as WorkOrderCategory | "")}
          >
            <option value="">{t("tasks.create.noCategory")}</option>
            {WORK_ORDER_CATEGORIES.map((c) => (
              <option key={c} value={c}>
                {t(`tasks.category.${c}`)}
              </option>
            ))}
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="task_priority">{t("tasks.create.priority")}</Label>
          <Select id="task_priority" value={priority} onChange={(e) => setPriority(e.target.value as TaskPriority)}>
            {TASK_PRIORITIES.map((p) => (
              <option key={p} value={p}>
                {t(`tasks.priority.${p}`)}
              </option>
            ))}
          </Select>
        </div>
        {canViewMembers && (
          <div className="space-y-1.5">
            <Label htmlFor="task_assignee">{t("tasks.create.assignee")}</Label>
            <Select id="task_assignee" value={assigneeId} onChange={(e) => setAssigneeId(e.target.value)}>
              <option value="">{t("tasks.create.unassigned")}</option>
              {members.map((m) => (
                <option key={m.user_id} value={m.user_id}>
                  {m.name}
                </option>
              ))}
            </Select>
          </div>
        )}
      </div>

      <div className="flex justify-end gap-2 pt-2">
        <Button type="button" variant="outline" onClick={onDone}>
          {t("tasks.create.cancel")}
        </Button>
        <Button type="submit" disabled={create.isPending || !title.trim()}>
          {create.isPending ? <Spinner /> : <Plus className="size-4" />}
          {t("tasks.create.submit")}
        </Button>
      </div>
    </form>
  );
}
