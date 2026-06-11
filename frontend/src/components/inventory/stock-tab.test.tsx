import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { StockTab } from "./stock-tab";
import { API_URL } from "@/lib/config";
import { makeItem, makeSession, makeStockAnalytics } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

describe("StockTab", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders current stock with case conversion and min", async () => {
    renderWithProviders(<StockTab item={makeItem()} canManage />);

    expect(await screen.findByText("Current Stock")).toBeInTheDocument();
    expect(screen.getByText("(12 cases)")).toBeInTheDocument(); // 150 / 12
    expect(screen.getByText("Min: 50 bottles")).toBeInTheDocument();
    expect(screen.getByText("bottles exited")).toBeInTheDocument();
  });

  it("shows realized + money metrics for finance users", async () => {
    renderWithProviders(<StockTab item={makeItem()} canManage />);

    expect(await screen.findByText("Mean price (realized, 12m)")).toBeInTheDocument();
    expect(screen.getByText("Sales value (at realized price)")).toBeInTheDocument();
    expect(screen.getByText("Revenue (realized)")).toBeInTheDocument();
  });

  it("hides money metrics without financials.view", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () =>
        HttpResponse.json({ data: makeSession({ roles: ["INVENTORY"] }) }),
      ),
    );

    renderWithProviders(<StockTab item={makeItem()} canManage />);

    expect(await screen.findByText("Current Stock")).toBeInTheDocument();
    expect(screen.queryByText("Mean price (realized, 12m)")).not.toBeInTheDocument();
    expect(screen.queryByText("Revenue (realized)")).not.toBeInTheDocument();
    // Non-money stats stay.
    expect(screen.getByText("Days of stock left")).toBeInTheDocument();
  });

  it("re-queries when the exit period changes", async () => {
    let lastPeriod: string | null = null;
    server.use(
      http.get(`${API_URL}/inventory-items/:id/stock-analytics`, ({ request }) => {
        lastPeriod = new URL(request.url).searchParams.get("period");
        return HttpResponse.json({ data: makeStockAnalytics({ period: lastPeriod ?? "30d" }) });
      }),
    );

    renderWithProviders(<StockTab item={makeItem()} canManage />);
    const user = userEvent.setup();
    await screen.findByText("Current Stock");
    await user.click(screen.getByRole("tab", { name: "YTD" }));

    await waitFor(() => expect(lastPeriod).toBe("ytd"));
  });

  it("records a quick stock entry with a note", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/inventory-items/:id/stock`, async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeItem() });
      }),
    );

    renderWithProviders(<StockTab item={makeItem()} canManage />);
    const user = userEvent.setup();
    await screen.findByText("Quick Stock Entry");

    await user.selectOptions(screen.getByLabelText("Type"), "MANUAL_OUT");
    await user.type(screen.getByLabelText("Quantity (bottles)"), "5");
    await user.type(screen.getByLabelText("Note"), "Breakage");
    await user.click(screen.getByRole("button", { name: "Add" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      type: "MANUAL_OUT",
      quantity: -5, // stock out → negative
      note: "Breakage",
      is_reconciliation: false,
    });
  });

  it("shows the exit-by-channel breakdown", async () => {
    renderWithProviders(<StockTab item={makeItem()} canManage />);

    expect(await screen.findByText("Sales")).toBeInTheDocument();
    expect(screen.getByText("Manual")).toBeInTheDocument();
    expect(screen.getByText("Production")).toBeInTheDocument();
  });
});
