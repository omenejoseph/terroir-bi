import { describe, expect, it, beforeEach } from "vitest";

import AnalyticsPage from "./page";
import { renderWithProviders, screen, seedAuth, seedLocale } from "@/test/utils";

describe("Inventory analytics page", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders summary cards, the exit portfolio, and items-by-group", async () => {
    renderWithProviders(<AnalyticsPage />);

    expect(await screen.findByText("Inventory Analytics")).toBeInTheDocument();

    // Summary.
    expect(screen.getByText("Total Products")).toBeInTheDocument();
    expect(screen.getByText("Sale Value")).toBeInTheDocument();
    // Sale Value now renders 2dp like every money figure (was 0dp), so the same
    // amount can appear more than once.
    expect(screen.getAllByText("4.498,50 €").length).toBeGreaterThan(0); // 150 × €29.99
    expect(screen.getByText("Margin: 100%")).toBeInTheDocument();

    // Warehouse exit portfolio.
    expect(screen.getByText("Warehouse exit — portfolio")).toBeInTheDocument();
    expect(screen.getByText("External · outside sales")).toBeInTheDocument();
    expect(screen.getByText("Blended / total")).toBeInTheDocument();
    expect(screen.getByText("33.3%")).toBeInTheDocument(); // off-target

    // Items by group (plain list, not a chart).
    expect(screen.getByText("Packaging")).toBeInTheDocument();
    expect(screen.getByText("Wine")).toBeInTheDocument();
  });
});
