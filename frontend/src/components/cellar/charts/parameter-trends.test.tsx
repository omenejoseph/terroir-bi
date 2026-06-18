import { beforeEach, describe, expect, it } from "vitest";

import { ParameterTrends } from "@/components/cellar/charts/parameter-trends";
import { renderWithProviders, screen, seedLocale } from "@/test/utils";
import type { AnalysisTrendPoint } from "@/lib/types";

describe("ParameterTrends", () => {
  beforeEach(() => seedLocale("en"));

  it("shows an empty state when there are no analyses", () => {
    renderWithProviders(<ParameterTrends points={[]} />);
    expect(screen.getByText("No analyses yet.")).toBeInTheDocument();
  });

  it("renders a mini chart only for parameters that have data", () => {
    const points: AnalysisTrendPoint[] = [
      { id: "1", date: "2026-06-01T00:00:00Z", vessel_id: null, vessel_name: null, free_so2: 25, ph: 3.4 },
      { id: "2", date: "2026-06-10T00:00:00Z", vessel_id: null, vessel_name: null, free_so2: 30, ph: 3.5 },
    ];
    renderWithProviders(<ParameterTrends points={points} />);
    expect(screen.getByText("Free SO₂")).toBeInTheDocument();
    expect(screen.getByText("pH")).toBeInTheDocument();
    // A parameter with no data is not charted.
    expect(screen.queryByText("Brix")).not.toBeInTheDocument();
  });
});
