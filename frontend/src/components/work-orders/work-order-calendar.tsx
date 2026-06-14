"use client";

import * as React from "react";
import { Plus } from "lucide-react";

import { useUpdateWorkOrderStatus } from "@/hooks/use-work-orders";
import { useFormatters } from "@/lib/format";
import { useTranslation } from "@/i18n/context";
import type { TaskStatus, WorkOrder } from "@/lib/types";
import {
  eachDay,
  endOfQuarter,
  endOfWeek,
  isToday,
  monthWeeks,
  placeInWeek,
  quarterMonths,
  startOfDay,
  startOfMonth,
  startOfQuarter,
  startOfWeek,
  type Interval,
} from "@/lib/calendar";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { STATUS_BAR, STATUS_DOT, workOrderInterval } from "@/components/work-orders/constants";

export type Granularity = "day" | "week" | "month" | "quarter";

interface CalendarProps {
  workOrders: WorkOrder[];
  granularity: Granularity;
  anchor: Date;
  onSelect: (wo: WorkOrder) => void;
  onPickDate: (date: Date) => void;
  /** Quick-add a work order for a given day (today or later). */
  onAddForDate?: (date: Date) => void;
}

/** Whether a day is today or in the future (quick-add is disallowed in the past). */
function isTodayOrFuture(day: Date): boolean {
  return day.getTime() >= startOfDay(new Date()).getTime();
}

const WEEKDAY_KEYS = [
  "tasks.calendar.weekdayMon",
  "tasks.calendar.weekdayTue",
  "tasks.calendar.weekdayWed",
  "tasks.calendar.weekdayThu",
  "tasks.calendar.weekdayFri",
  "tasks.calendar.weekdaySat",
  "tasks.calendar.weekdaySun",
] as const;

export function WorkOrderCalendar({
  workOrders,
  granularity,
  anchor,
  onSelect,
  onPickDate,
  onAddForDate,
}: CalendarProps) {
  const byId = React.useMemo(() => new Map(workOrders.map((w) => [w.id, w])), [workOrders]);
  const intervals = React.useMemo<Interval[]>(
    () =>
      workOrders.flatMap((w) => {
        const iv = workOrderInterval(w);
        return iv ? [{ id: w.id, from: iv.from, to: iv.to }] : [];
      }),
    [workOrders],
  );
  const unscheduled = React.useMemo(
    () => workOrders.filter((w) => !workOrderInterval(w)),
    [workOrders],
  );

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        {granularity === "day" && (
          <DayView
            anchor={anchor}
            byId={byId}
            intervals={intervals}
            onSelect={onSelect}
            onAddForDate={onAddForDate}
          />
        )}
        {granularity === "week" && (
          <WeekBand
            days={eachDay(startOfWeek(anchor), endOfWeek(anchor))}
            byId={byId}
            intervals={intervals}
            onSelect={onSelect}
            onAddForDate={onAddForDate}
            showWeekday
            tall
          />
        )}
        {granularity === "month" && (
          <MonthView
            anchor={anchor}
            byId={byId}
            intervals={intervals}
            onSelect={onSelect}
            onAddForDate={onAddForDate}
          />
        )}
        {granularity === "quarter" && (
          <QuarterView
            anchor={anchor}
            byId={byId}
            intervals={intervals}
            onPickDate={onPickDate}
            onSelect={onSelect}
          />
        )}

        {granularity !== "quarter" && unscheduled.length > 0 && (
          <UnscheduledStrip workOrders={unscheduled} onSelect={onSelect} />
        )}
      </CardContent>
    </Card>
  );
}

// ── Building blocks ─────────────────────────────────────────────────────────

/** A complete/uncomplete checkbox for a work order; toggles its status. */
function TaskCompleteCheckbox({ wo, className }: { wo: WorkOrder; className?: string }) {
  const { t } = useTranslation();
  const updateStatus = useUpdateWorkOrderStatus();
  return (
    <Checkbox
      checked={wo.status === "DONE"}
      onChange={(e) => updateStatus.mutate({ id: wo.id, status: e.target.checked ? "DONE" : "TODO" })}
      onClick={(e) => e.stopPropagation()}
      aria-label={t("tasks.complete")}
      className={className}
    />
  );
}

function Bar({
  wo,
  startsHere,
  endsHere,
  onSelect,
  style,
}: {
  wo: WorkOrder;
  startsHere: boolean;
  endsHere: boolean;
  onSelect: (wo: WorkOrder) => void;
  style?: React.CSSProperties;
}) {
  return (
    <div
      style={style}
      className={cn(
        "flex min-w-0 items-center gap-1 border px-1 text-xs leading-5",
        STATUS_BAR[wo.status],
        startsHere ? "rounded-l-md" : "rounded-l-none border-l-0",
        endsHere ? "rounded-r-md" : "rounded-r-none border-r-0",
      )}
    >
      {/* Only the first segment of a multi-week span carries the checkbox. */}
      {startsHere && <TaskCompleteCheckbox wo={wo} className="size-3 shrink-0" />}
      <button
        type="button"
        onClick={() => onSelect(wo)}
        title={wo.title}
        className={cn(
          "min-w-0 flex-1 truncate text-left transition-opacity hover:opacity-80",
          wo.status === "DONE" && "line-through opacity-70",
        )}
      >
        {wo.title}
      </button>
    </div>
  );
}

/** One Monday-based week row: a date header strip + lane-stacked spanning bars. */
function WeekBand({
  days,
  byId,
  intervals,
  onSelect,
  onAddForDate,
  monthRef,
  showWeekday,
  tall,
}: {
  days: Date[];
  byId: Map<string, WorkOrder>;
  intervals: Interval[];
  onSelect: (wo: WorkOrder) => void;
  onAddForDate?: (date: Date) => void;
  /** When set, days outside this month are muted (month grid). */
  monthRef?: Date;
  showWeekday?: boolean;
  tall?: boolean;
}) {
  const { t } = useTranslation();
  const placements = placeInWeek(intervals, days[0]);
  const laneCount = placements.reduce((max, p) => Math.max(max, p.lane + 1), 0);

  return (
    <div className="border-t border-border first:border-t-0">
      <div className="grid grid-cols-7">
        {days.map((day, i) => {
          const muted = monthRef ? day.getMonth() !== monthRef.getMonth() : false;
          const canAdd = onAddForDate && isTodayOrFuture(day);
          return (
            <div key={day.toISOString()} className="group/day flex items-center justify-between px-1.5 pt-1">
              <div className="min-w-0">
                {showWeekday && (
                  <div className="text-[10px] uppercase tracking-wide text-muted-foreground">
                    {t(WEEKDAY_KEYS[i])}
                  </div>
                )}
                <div
                  className={cn(
                    "flex h-6 w-6 items-center justify-center rounded-full text-xs tabular-nums",
                    muted && "text-muted-foreground/50",
                    isToday(day) && "bg-primary font-semibold text-primary-foreground",
                  )}
                >
                  {day.getDate()}
                </div>
              </div>
              {canAdd && (
                <button
                  type="button"
                  aria-label={t("tasks.addForDay", { date: day.getDate() })}
                  onClick={() => onAddForDate(day)}
                  className="rounded p-0.5 text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-foreground focus-visible:opacity-100 group-hover/day:opacity-100"
                >
                  <Plus className="size-3.5" />
                </button>
              )}
            </div>
          );
        })}
      </div>
      <div
        className="grid grid-cols-7 gap-y-1 px-1 pb-2 pt-1"
        style={{
          gridAutoRows: tall ? "1.5rem" : "1.25rem",
          minHeight: laneCount ? undefined : tall ? "3rem" : "0.75rem",
        }}
      >
        {placements.map((p) => {
          const wo = byId.get(p.id);
          if (!wo) return null;
          return (
            <Bar
              key={p.id}
              wo={wo}
              startsHere={p.startsHere}
              endsHere={p.endsHere}
              onSelect={onSelect}
              style={{ gridColumn: `${p.colStart + 1} / span ${p.span}`, gridRow: p.lane + 1 }}
            />
          );
        })}
      </div>
    </div>
  );
}

function DayView({
  anchor,
  byId,
  intervals,
  onSelect,
  onAddForDate,
}: {
  anchor: Date;
  byId: Map<string, WorkOrder>;
  intervals: Interval[];
  onSelect: (wo: WorkOrder) => void;
  onAddForDate?: (date: Date) => void;
}) {
  const { t } = useTranslation();
  const { date } = useFormatters();
  const todays = intervals
    .filter((iv) => iv.from <= anchor && anchor <= iv.to)
    .map((iv) => byId.get(iv.id))
    .filter((w): w is WorkOrder => !!w);
  const canAdd = onAddForDate && isTodayOrFuture(anchor);

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        <h2 className="text-sm font-semibold">{date(anchor)}</h2>
        {canAdd && (
          <Button variant="outline" size="sm" onClick={() => onAddForDate(anchor)}>
            <Plus className="size-4" />
            {t("tasks.addForThisDay")}
          </Button>
        )}
      </div>
      {todays.length === 0 ? (
        <p className="py-8 text-center text-sm text-muted-foreground">{t("tasks.calendar.noItems")}</p>
      ) : (
        <ul className="space-y-2">
          {todays.map((wo) => (
            <li
              key={wo.id}
              className={cn(
                "flex items-center gap-2 rounded-md border px-3 py-2 text-sm",
                STATUS_BAR[wo.status],
              )}
            >
              <TaskCompleteCheckbox wo={wo} />
              <button
                type="button"
                onClick={() => onSelect(wo)}
                className="flex flex-1 items-center gap-2 text-left hover:opacity-80"
              >
                <span
                  className={cn(
                    "flex-1 truncate font-medium",
                    wo.status === "DONE" && "line-through opacity-70",
                  )}
                >
                  {wo.title}
                </span>
                {wo.assignee && <span className="text-xs opacity-80">{wo.assignee.name}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function MonthView({
  anchor,
  byId,
  intervals,
  onSelect,
  onAddForDate,
}: {
  anchor: Date;
  byId: Map<string, WorkOrder>;
  intervals: Interval[];
  onSelect: (wo: WorkOrder) => void;
  onAddForDate?: (date: Date) => void;
}) {
  const { t } = useTranslation();
  const weeks = monthWeeks(anchor);
  const monthRef = startOfMonth(anchor);

  return (
    <div>
      <div className="grid grid-cols-7">
        {WEEKDAY_KEYS.map((key) => (
          <div key={key} className="px-1.5 pb-1 text-[10px] uppercase tracking-wide text-muted-foreground">
            {t(key)}
          </div>
        ))}
      </div>
      {weeks.map((days) => (
        <WeekBand
          key={days[0].toISOString()}
          days={days}
          byId={byId}
          intervals={intervals}
          onSelect={onSelect}
          onAddForDate={onAddForDate}
          monthRef={monthRef}
        />
      ))}
    </div>
  );
}

function QuarterView({
  anchor,
  byId,
  intervals,
  onPickDate,
  onSelect,
}: {
  anchor: Date;
  byId: Map<string, WorkOrder>;
  intervals: Interval[];
  onPickDate: (date: Date) => void;
  onSelect: (wo: WorkOrder) => void;
}) {
  const { t } = useTranslation();
  const { date: fmtDate, monthShort } = useFormatters();
  const months = quarterMonths(anchor);

  // Tasks scheduled within the quarter, earliest first — checkable below the grids.
  const qStart = startOfQuarter(anchor);
  const qEnd = endOfQuarter(anchor);
  const quarterTasks = intervals
    .filter((iv) => iv.to >= qStart && iv.from <= qEnd)
    .sort((a, b) => a.from.getTime() - b.from.getTime())
    .map((iv) => ({ wo: byId.get(iv.id), from: iv.from }))
    .filter((row): row is { wo: WorkOrder; from: Date } => !!row.wo);

  return (
    <div className="space-y-6">
      <div className="grid gap-6 lg:grid-cols-3">
      {months.map((month) => (
        <div key={month.toISOString()} className="space-y-2">
          <h3 className="text-sm font-semibold">{monthShort(month)}</h3>
          <div className="grid grid-cols-7 gap-px">
            {WEEKDAY_KEYS.map((key) => (
              <div key={key} className="pb-1 text-center text-[9px] uppercase text-muted-foreground">
                {t(key).slice(0, 1)}
              </div>
            ))}
            {monthWeeks(month)
              .flat()
              .map((day) => {
                const muted = day.getMonth() !== month.getMonth();
                const statuses = intervals
                  .filter((iv) => iv.from <= day && day <= iv.to)
                  .map((iv) => byId.get(iv.id)?.status)
                  .filter((s): s is TaskStatus => !!s);
                return (
                  <button
                    key={day.toISOString()}
                    type="button"
                    aria-label={fmtDate(day)}
                    onClick={() => onPickDate(day)}
                    className={cn(
                      "flex aspect-square flex-col items-center justify-center rounded text-[10px] tabular-nums hover:bg-muted/60",
                      muted && "text-muted-foreground/40",
                      isToday(day) && "ring-1 ring-primary",
                    )}
                  >
                    <span>{day.getDate()}</span>
                    {statuses.length > 0 && (
                      <span className="mt-0.5 flex gap-0.5">
                        {statuses.slice(0, 3).map((s, i) => (
                          <span key={i} className={cn("size-1 rounded-full", STATUS_DOT[s])} />
                        ))}
                      </span>
                    )}
                  </button>
                );
              })}
          </div>
        </div>
      ))}
      </div>

      {quarterTasks.length > 0 && (
        <ul className="space-y-1.5 border-t border-border pt-4">
          {quarterTasks.map(({ wo, from }) => (
            <li
              key={wo.id}
              className="flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm"
            >
              <TaskCompleteCheckbox wo={wo} />
              <button
                type="button"
                onClick={() => onSelect(wo)}
                className="flex flex-1 items-center gap-2 text-left hover:opacity-80"
              >
                <span
                  className={cn(
                    "flex-1 truncate font-medium",
                    wo.status === "DONE" && "line-through opacity-70",
                  )}
                >
                  {wo.title}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">{fmtDate(from)}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function UnscheduledStrip({
  workOrders,
  onSelect,
}: {
  workOrders: WorkOrder[];
  onSelect: (wo: WorkOrder) => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="border-t border-border pt-3">
      <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {t("tasks.calendar.unscheduled")}
      </p>
      <div className="flex flex-wrap gap-2">
        {workOrders.map((wo) => (
          <button
            key={wo.id}
            type="button"
            onClick={() => onSelect(wo)}
            className={cn("rounded-md border px-2 py-1 text-xs hover:opacity-80", STATUS_BAR[wo.status])}
          >
            {wo.title}
          </button>
        ))}
      </div>
    </div>
  );
}
