import type { TaskPriority, TaskStatus, WorkOrder } from "@/lib/types";
import { startOfDay } from "@/lib/calendar";

export const PRIORITY_VARIANT: Record<TaskPriority, "secondary" | "default" | "outline"> = {
  LOW: "secondary",
  MEDIUM: "outline",
  HIGH: "default",
};

/** Bar/chip colour per status, used across the calendar views. */
export const STATUS_BAR: Record<TaskStatus, string> = {
  TODO: "bg-sky-500/15 text-sky-700 dark:text-sky-300 border-sky-500/30",
  IN_PROGRESS: "bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30",
  DONE: "bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/30",
};

export const STATUS_DOT: Record<TaskStatus, string> = {
  TODO: "bg-sky-500",
  IN_PROGRESS: "bg-amber-500",
  DONE: "bg-emerald-500",
};

/**
 * The day-range a work order occupies on the calendar: start_date → due_date,
 * falling back to whichever single date is present. Returns null when neither
 * date is set (the work order is "unscheduled").
 */
export function workOrderInterval(wo: WorkOrder): { from: Date; to: Date } | null {
  const start = wo.start_date ? startOfDay(new Date(wo.start_date)) : null;
  const due = wo.due_date ? startOfDay(new Date(wo.due_date)) : null;
  const from = start ?? due;
  const to = due ?? start;
  if (!from || !to) return null;
  return from <= to ? { from, to } : { from: to, to: from };
}
