import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import InflowAnalyticsPage from "./page";
import { API_URL } from "@/lib/config";
import { makeInflowAnalytics } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("InflowAnalyticsPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the summary cards and charts", async () => {
    renderWithProviders(<InflowAnalyticsPage />);

    expect(await screen.findByText("Cash Inflow Analytics")).toBeInTheDocument();
    expect(screen.getAllByText("Invoiced").length).toBeGreaterThan(0);
    expect(screen.getByText("Collected")).toBeInTheDocument();
    expect(screen.getByText("Net Cash Flow")).toBeInTheDocument();
    expect(screen.getByText("+60,00 €")).toBeInTheDocument(); // net 6000 with + sign
    expect(screen.getByText("Inflow Trends")).toBeInTheDocument();
    expect(screen.getByText("Customer Revenue")).toBeInTheDocument();
    expect(screen.getByText("Konoba")).toBeInTheDocument(); // a customer-revenue row
  });

  it("sends a date range when a preset is chosen", async () => {
    const ranges: { from: string | null; to: string | null }[] = [];
    server.use(
      http.get(`${API_URL}/inflows/analytics`, ({ request }) => {
        const u = new URL(request.url).searchParams;
        ranges.push({ from: u.get("from"), to: u.get("to") });
        return HttpResponse.json({ data: makeInflowAnalytics() });
      }),
    );

    renderWithProviders(<InflowAnalyticsPage />);
    const user = userEvent.setup();
    await screen.findByText("Cash Inflow Analytics");

    await user.click(screen.getByRole("button", { name: "Last Year" }));
    await waitFor(() => {
      const last = ranges.at(-1);
      expect(last?.from).toMatch(/-01-01$/);
      expect(last?.to).toMatch(/-12-31$/);
    });
  });
});
