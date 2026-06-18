import { describe, expect, it } from "vitest";

import { fillRatio, lotStatusVariant, so2Color, wineFill } from "@/lib/cellar-colors";

describe("cellar-colors", () => {
  it("clamps the fill ratio to [0,1]", () => {
    expect(fillRatio("500", "1000")).toBe(0.5);
    expect(fillRatio("2000", "1000")).toBe(1);
    expect(fillRatio("-5", "1000")).toBe(0);
    expect(fillRatio("10", "0")).toBe(0);
  });

  it("maps wine type to a fill colour with a neutral default", () => {
    expect(wineFill("RED")).toBe("#6b2138");
    expect(wineFill(null)).toBe("#8a8f98");
  });

  it("maps lot status to a badge variant", () => {
    expect(lotStatusVariant("FERMENTING")).toBe("warning");
    expect(lotStatusVariant("READY")).toBe("success");
    expect(lotStatusVariant("BOTTLED")).toBe("info");
  });

  it("colours SO2 by health band", () => {
    expect(so2Color(null)).toBe("#9ca3af");
    expect(so2Color(10)).toBe("#dc2626"); // critical
    expect(so2Color(30)).toBe("#16a34a"); // ok
    expect(so2Color(60)).toBe("#2563eb"); // high
  });
});
