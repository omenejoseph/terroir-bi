/**
 * Small, dependency-free calendar helpers used by the work-order calendar
 * views. Weeks are Monday-based (European convention). All functions operate on
 * local-time `Date`s normalised to midnight so day comparisons are stable.
 */

export function startOfDay(d: Date): Date {
  const x = new Date(d);
  x.setHours(0, 0, 0, 0);
  return x;
}

export function addDays(d: Date, n: number): Date {
  const x = startOfDay(d);
  x.setDate(x.getDate() + n);
  return x;
}

export function addMonths(d: Date, n: number): Date {
  const x = startOfDay(d);
  x.setDate(1);
  x.setMonth(x.getMonth() + n);
  return x;
}

export function isSameDay(a: Date, b: Date): boolean {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

export function isToday(d: Date): boolean {
  return isSameDay(d, new Date());
}

/** Whole-day difference (b - a), ignoring time-of-day. */
export function diffDays(a: Date, b: Date): number {
  const ms = startOfDay(b).getTime() - startOfDay(a).getTime();
  return Math.round(ms / 86_400_000);
}

/** Monday as the first day of the week. */
export function startOfWeek(d: Date): Date {
  const x = startOfDay(d);
  const dow = (x.getDay() + 6) % 7; // 0 = Monday … 6 = Sunday
  return addDays(x, -dow);
}

export function endOfWeek(d: Date): Date {
  return addDays(startOfWeek(d), 6);
}

export function startOfMonth(d: Date): Date {
  const x = startOfDay(d);
  x.setDate(1);
  return x;
}

export function endOfMonth(d: Date): Date {
  const x = startOfMonth(d);
  x.setMonth(x.getMonth() + 1);
  return addDays(x, -1);
}

export function startOfQuarter(d: Date): Date {
  const x = startOfMonth(d);
  x.setMonth(Math.floor(x.getMonth() / 3) * 3);
  return x;
}

export function endOfQuarter(d: Date): Date {
  const x = startOfQuarter(d);
  x.setMonth(x.getMonth() + 3);
  return addDays(x, -1);
}

/** Inclusive list of days between `start` and `end`. */
export function eachDay(start: Date, end: Date): Date[] {
  const days: Date[] = [];
  let cursor = startOfDay(start);
  const last = startOfDay(end);
  while (cursor <= last) {
    days.push(cursor);
    cursor = addDays(cursor, 1);
  }
  return days;
}

/**
 * The weeks (Mon–Sun rows) that fully cover the month containing `d`, including
 * the spill-over days from the adjacent months needed to square off the grid.
 */
export function monthWeeks(d: Date): Date[][] {
  const gridStart = startOfWeek(startOfMonth(d));
  const gridEnd = endOfWeek(endOfMonth(d));
  const days = eachDay(gridStart, gridEnd);
  const weeks: Date[][] = [];
  for (let i = 0; i < days.length; i += 7) {
    weeks.push(days.slice(i, i + 7));
  }
  return weeks;
}

/** The three month-anchor dates (1st of each month) for the quarter of `d`. */
export function quarterMonths(d: Date): Date[] {
  const start = startOfQuarter(d);
  return [start, addMonths(start, 1), addMonths(start, 2)];
}

export interface Interval {
  id: string;
  from: Date;
  to: Date;
}

/**
 * Assign each interval to the lowest lane (row) that has no overlap with an
 * interval already placed there — the standard greedy layout used for calendar
 * "event" stacking. Returns a map of interval id → lane index (0-based).
 */
export function assignLanes(intervals: Interval[]): Map<string, number> {
  const sorted = [...intervals].sort(
    (a, b) => a.from.getTime() - b.from.getTime() || b.to.getTime() - a.to.getTime(),
  );
  const laneEnds: Date[] = []; // last `to` placed in each lane
  const lanes = new Map<string, number>();
  for (const iv of sorted) {
    let lane = laneEnds.findIndex((end) => end < iv.from);
    if (lane === -1) {
      lane = laneEnds.length;
      laneEnds.push(iv.to);
    } else {
      laneEnds[lane] = iv.to;
    }
    lanes.set(iv.id, lane);
  }
  return lanes;
}

export interface WeekPlacement {
  id: string;
  /** 0–6 column index where the bar starts within this week. */
  colStart: number;
  /** Number of columns the bar spans (1–7). */
  span: number;
  /** Lane (row) within the week band. */
  lane: number;
  /** Whether the interval actually begins / ends inside this week. */
  startsHere: boolean;
  endsHere: boolean;
}

/**
 * Clip each interval to the given Monday-based week and compute its column
 * position, span and lane. Intervals that don't intersect the week are dropped.
 */
export function placeInWeek(intervals: Interval[], weekStart: Date): WeekPlacement[] {
  const weekEnd = addDays(weekStart, 6);
  const clipped: { iv: Interval; clipFrom: Date; clipTo: Date }[] = [];
  for (const iv of intervals) {
    if (iv.to < weekStart || iv.from > weekEnd) continue;
    const clipFrom = iv.from < weekStart ? weekStart : iv.from;
    const clipTo = iv.to > weekEnd ? weekEnd : iv.to;
    clipped.push({ iv, clipFrom, clipTo });
  }
  const lanes = assignLanes(clipped.map(({ iv, clipFrom, clipTo }) => ({ id: iv.id, from: clipFrom, to: clipTo })));
  return clipped.map(({ iv, clipFrom, clipTo }) => ({
    id: iv.id,
    colStart: diffDays(weekStart, clipFrom),
    span: diffDays(clipFrom, clipTo) + 1,
    lane: lanes.get(iv.id) ?? 0,
    startsHere: clipFrom.getTime() === startOfDay(iv.from).getTime(),
    endsHere: clipTo.getTime() === startOfDay(iv.to).getTime(),
  }));
}
