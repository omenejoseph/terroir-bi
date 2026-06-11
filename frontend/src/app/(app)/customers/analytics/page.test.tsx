import { describe, expect, it, beforeEach } from "vitest";

import AnalyticsPage from "./page";
import { renderWithProviders, screen, seedAuth, seedLocale } from "@/test/utils";

describe("Customer analytics page", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders summary cards and the per-customer table", async () => {
    renderWithProviders(<AnalyticsPage />);

    expect(await screen.findByText("Customer Analytics")).toBeInTheDocument();

    // Summary.
    expect(screen.getByText("Active Customers")).toBeInTheDocument();
    expect(screen.getByText("Revenue last 12 months")).toBeInTheDocument();
    expect(screen.getByText("Top Customer (12m)")).toBeInTheDocument();
    expect(screen.getAllByText("Acme Corporation").length).toBeGreaterThan(0);

    // Per-customer row.
    expect(screen.getByText("John Smith")).toBeInTheDocument();
    expect(screen.getAllByText("99,95 €").length).toBeGreaterThan(0); // revenue 12m
    // Last order relative + no gap/expected for a single order.
    expect(screen.getByText("2 days ago")).toBeInTheDocument();
  });
});
