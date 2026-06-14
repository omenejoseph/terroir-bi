/**
 * Dashboard period model. The selector sends a preset token to the backend
 * (which owns the authoritative date math); the client resolves the same window
 * locally only to render the human range label and to send explicit from/to for
 * a custom range.
 */

export type DashboardPeriod =
  | "today"
  | "yesterday"
  | "mtd"
  | "qtd"
  | "ytd"
  | "month"
  | "last-month"
  | "last-year"
  | "all"
  | "custom";

/** Preset chips, in display order (custom is handled separately). */
export const DASHBOARD_PERIODS: Exclude<DashboardPeriod, "custom">[] = [
  "today",
  "yesterday",
  "mtd",
  "qtd",
  "ytd",
  "month",
  "last-month",
  "last-year",
  "all",
];

export interface PeriodWindow {
  since: Date | null;
  until: Date | null;
}

const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
const endOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23, 59, 59, 999);

/** Resolve a period (with optional custom from/to ISO dates) to a date window. */
export function resolvePeriodWindow(period: DashboardPeriod, from?: string, to?: string): PeriodWindow {
  const now = new Date();
  const y = now.getFullYear();
  const m = now.getMonth();

  switch (period) {
    case "today":
      return { since: startOfDay(now), until: now };
    case "yesterday": {
      const yest = new Date(y, m, now.getDate() - 1);
      return { since: startOfDay(yest), until: endOfDay(yest) };
    }
    case "mtd":
      return { since: new Date(y, m, 1), until: now };
    case "qtd":
      return { since: new Date(y, Math.floor(m / 3) * 3, 1), until: now };
    case "ytd":
      return { since: new Date(y, 0, 1), until: now };
    case "month":
      return { since: new Date(y, m, 1), until: endOfDay(new Date(y, m + 1, 0)) };
    case "last-month":
      return { since: new Date(y, m - 1, 1), until: endOfDay(new Date(y, m, 0)) };
    case "last-year":
      return { since: new Date(y - 1, 0, 1), until: endOfDay(new Date(y - 1, 11, 31)) };
    case "all":
      return { since: null, until: null };
    case "custom":
      return {
        since: from ? startOfDay(new Date(from)) : null,
        until: to ? endOfDay(new Date(to)) : null,
      };
  }
}

/** Format a window as "1 Jun → 14 Jun 2026" (or a single day, or null when open-ended). */
export function formatPeriodRange(window: PeriodWindow, locale: string): string | null {
  const { since, until } = window;
  if (!since || !until) return null;

  const fmt = (d: Date, omitYear: boolean) =>
    d.toLocaleDateString(locale, {
      month: "short",
      day: "numeric",
      ...(omitYear ? {} : { year: "numeric" }),
    });

  const sameDay =
    since.getFullYear() === until.getFullYear() &&
    since.getMonth() === until.getMonth() &&
    since.getDate() === until.getDate();
  if (sameDay) return fmt(since, false);

  const sameYear = since.getFullYear() === until.getFullYear();
  return `${fmt(since, sameYear)} → ${fmt(until, false)}`;
}

/** Local YYYY-MM-DD (not UTC) for sending a custom range to the backend. */
export function toISODate(d: Date): string {
  const month = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${month}-${day}`;
}
