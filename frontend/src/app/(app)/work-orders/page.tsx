"use client";

import * as React from "react";
import { ChevronLeft, ChevronRight, Plus } from "lucide-react";

import { ApiError } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/context";
import { useMembers } from "@/hooks/use-team";
import {
  useCreateWorkOrder,
  useWorkOrders,
  useWorkOrderStats,
} from "@/hooks/use-work-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { TaskPriority, WorkOrder } from "@/lib/types";
import { TASK_PRIORITIES } from "@/lib/types";
import { addDays, addMonths, endOfWeek, startOfDay, startOfWeek } from "@/lib/calendar";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";
import { WorkOrderBoard } from "@/components/work-orders/work-order-board";
import { WorkOrderCalendar, type Granularity } from "@/components/work-orders/work-order-calendar";
import { WorkOrderDetailDialog } from "@/components/work-orders/work-order-detail-dialog";

type View = "board" | Granularity;

/** Due-date horizons for the stat summary, mirroring the dashboard. */
const STAT_RANGES = ["7D", "30D", "90D", "1Y", "ALL"];

export default function WorkOrdersPage() {
  const { t } = useTranslation();
  const { date, monthYear } = useFormatters();
  const [view, setView] = React.useState<View>("board");
  const [anchor, setAnchor] = React.useState<Date>(() => startOfDay(new Date()));
  const [selected, setSelected] = React.useState<WorkOrder | null>(null);
  const [search, setSearch] = React.useState("");
  const [debounced, setDebounced] = React.useState("");
  const [statsRange, setStatsRange] = React.useState("ALL");
  const [creating, setCreating] = React.useState(false);

  React.useEffect(() => {
    const id = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(id);
  }, [search]);

  const { data, isLoading, isError, error } = useWorkOrders(debounced ? { search: debounced } : {});
  const statsQ = useWorkOrderStats(statsRange);
  const rangeTabs = STAT_RANGES.map((r) => ({ value: r, label: r === "ALL" ? t("tasks.range.all") : r }));

  const workOrders = React.useMemo(
    () => [...(data ?? [])].sort((a, b) => a.sort_order - b.sort_order),
    [data],
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
          <Button onClick={() => setCreating((c) => !c)} className="shrink-0">
            <Plus className="size-4" />
            {t("tasks.create.open")}
          </Button>
        </div>
      </header>

      {/* Stat summary with a due-date timeline filter. */}
      <div className="space-y-3">
        <Tabs tabs={rangeTabs} value={statsRange} onChange={setStatsRange} />
        {statsQ.data && (
          <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
            <StatTile label={t("tasks.stats.todo")} value={statsQ.data.todo} />
            <StatTile label={t("tasks.stats.inProgress")} value={statsQ.data.in_progress} />
            <StatTile label={t("tasks.stats.done")} value={statsQ.data.done} />
            <StatTile label={t("tasks.stats.overdue")} value={statsQ.data.overdue} accent />
          </div>
        )}
      </div>

      {creating && <QuickCreateRow onDone={() => setCreating(false)} />}

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

      {!isLoading && !isError && view === "board" && <WorkOrderBoard workOrders={workOrders} />}

      {!isLoading && !isError && view !== "board" && (
        <WorkOrderCalendar
          workOrders={workOrders}
          granularity={view}
          anchor={anchor}
          onSelect={setSelected}
          onPickDate={(d) => {
            setAnchor(d);
            setView("day");
          }}
        />
      )}

      <WorkOrderDetailDialog workOrder={selectedLive} onClose={() => setSelected(null)} />
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

function QuickCreateRow({ onDone }: { onDone: () => void }) {
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
    onDone();
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
          <div className="flex gap-2">
            <Button type="submit" disabled={create.isPending || !title.trim()}>
              {create.isPending ? <Spinner /> : <Plus className="size-4" />}
              {t("tasks.create.submit")}
            </Button>
            <Button type="button" variant="outline" onClick={onDone}>
              {t("tasks.create.cancel")}
            </Button>
          </div>
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
      <Select id="task_assignee" value={value} onChange={(e) => onChange(e.target.value)} className="w-full sm:w-auto sm:max-w-xs">
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
