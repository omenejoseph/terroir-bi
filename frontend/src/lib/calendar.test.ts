import { describe, expect, it } from "vitest";

import {
  addDays,
  assignLanes,
  diffDays,
  eachDay,
  endOfQuarter,
  monthWeeks,
  placeInWeek,
  quarterMonths,
  startOfQuarter,
  startOfWeek,
  type Interval,
} from "./calendar";

const d = (s: string) => new Date(`${s}T00:00:00`);

describe("calendar helpers", () => {
  it("startOfWeek snaps to Monday", () => {
    // 2026-06-10 is a Wednesday → Monday is 2026-06-08.
    expect(startOfWeek(d("2026-06-10")).getDate()).toBe(8);
    // A Sunday belongs to the week that started the previous Monday.
    expect(startOfWeek(d("2026-06-14")).getDate()).toBe(8);
    // A Monday is its own week start.
    expect(startOfWeek(d("2026-06-08")).getDate()).toBe(8);
  });

  it("diffDays counts whole days inclusive of direction", () => {
    expect(diffDays(d("2026-06-01"), d("2026-06-04"))).toBe(3);
    expect(diffDays(d("2026-06-04"), d("2026-06-01"))).toBe(-3);
  });

  it("eachDay returns an inclusive range", () => {
    expect(eachDay(d("2026-06-01"), d("2026-06-03"))).toHaveLength(3);
  });

  it("monthWeeks covers the month in Monday-aligned 7-day rows", () => {
    const weeks = monthWeeks(d("2026-06-15"));
    expect(weeks.every((w) => w.length === 7)).toBe(true);
    // Every grid row starts on Monday.
    expect(weeks.every((w) => w[0].getDay() === 1)).toBe(true);
    // The grid includes June 1 and June 30 somewhere.
    const flat = weeks.flat().map((x) => x.toDateString());
    expect(flat).toContain(d("2026-06-01").toDateString());
    expect(flat).toContain(d("2026-06-30").toDateString());
  });

  it("quarter helpers bracket the right three months", () => {
    expect(startOfQuarter(d("2026-05-20")).getMonth()).toBe(3); // April
    expect(endOfQuarter(d("2026-05-20")).getMonth()).toBe(5); // June
    expect(quarterMonths(d("2026-05-20")).map((m) => m.getMonth())).toEqual([3, 4, 5]);
  });

  it("assignLanes stacks overlapping intervals onto separate lanes", () => {
    const intervals: Interval[] = [
      { id: "a", from: d("2026-06-01"), to: d("2026-06-05") },
      { id: "b", from: d("2026-06-03"), to: d("2026-06-08") }, // overlaps a
      { id: "c", from: d("2026-06-09"), to: d("2026-06-10") }, // disjoint → reuses lane 0
    ];
    const lanes = assignLanes(intervals);
    expect(lanes.get("a")).toBe(0);
    expect(lanes.get("b")).toBe(1);
    expect(lanes.get("c")).toBe(0);
  });

  it("placeInWeek clips spanning intervals to the week and flags the ends", () => {
    const weekStart = startOfWeek(d("2026-06-10")); // Mon 2026-06-08
    // Interval Wed→next-Tue spans into the following week.
    const placements = placeInWeek(
      [{ id: "x", from: d("2026-06-10"), to: d("2026-06-16") }],
      weekStart,
    );
    expect(placements).toHaveLength(1);
    const p = placements[0];
    expect(p.colStart).toBe(2); // Wednesday is the 3rd column
    expect(p.span).toBe(5); // Wed..Sun within this week
    expect(p.startsHere).toBe(true);
    expect(p.endsHere).toBe(false); // ends in the next week
  });

  it("addDays does not mutate the input", () => {
    const base = d("2026-06-10");
    addDays(base, 5);
    expect(base.getDate()).toBe(10);
  });
});
