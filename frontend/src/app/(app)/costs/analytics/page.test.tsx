import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import CostAnalyticsPage from "./page";
import { API_URL } from "@/lib/config";
import { makeCostAnalytics } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("CostAnalyticsPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the summary cards and charts", async () => {
    renderWithProviders(<CostAnalyticsPage />);

    expect(await screen.findByText("Cost Analytics")).toBeInTheDocument();
    // Cards.
    expect(screen.getByText("Invoiced")).toBeInTheDocument();
    expect(screen.getByText("4 invoices")).toBeInTheDocument(); // invoiced.count
    expect(screen.getByText("Paid")).toBeInTheDocument();
    expect(screen.getByText("Gross Margin")).toBeInTheDocument();
    expect(screen.getByText("73.1%")).toBeInTheDocument();
    expect(screen.getByText("Avg Days to Pay")).toBeInTheDocument();
    // Charts + top costs.
    expect(screen.getByText("Year over Year")).toBeInTheDocument();
    expect(screen.getByText("Top Costs")).toBeInTheDocument();
    expect(screen.getByText("Glass")).toBeInTheDocument(); // a top-cost row
  });

  it("sends a date range when a preset is chosen", async () => {
    const ranges: { from: string | null; to: string | null }[] = [];
    server.use(
      http.get(`${API_URL}/costs/analytics`, ({ request }) => {
        const u = new URL(request.url).searchParams;
        ranges.push({ from: u.get("from"), to: u.get("to") });
        return HttpResponse.json({ data: makeCostAnalytics() });
      }),
    );

    renderWithProviders(<CostAnalyticsPage />);
    const user = userEvent.setup();
    await screen.findByText("Cost Analytics");

    await user.click(screen.getByRole("button", { name: "Last Year" }));
    await waitFor(() => {
      const last = ranges.at(-1);
      expect(last?.from).toMatch(/-01-01$/);
      expect(last?.to).toMatch(/-12-31$/);
    });
  });
});
