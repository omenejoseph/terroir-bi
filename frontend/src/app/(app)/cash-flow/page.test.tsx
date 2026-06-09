import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import CashFlowPage from "./page";
import { API_URL } from "@/lib/config";
import { makeSession } from "@/test/fixtures";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
} from "@/test/utils";
import { server } from "@/test/mocks/server";

describe("CashFlowPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the forecast summary cards including the growth percentage", async () => {
    renderWithProviders(<CashFlowPage />);
    expect(await screen.findByText("Avg monthly revenue")).toBeInTheDocument();
    // revenue_growth_percent is rendered verbatim with a % suffix.
    expect(screen.getByText("4.00%")).toBeInTheDocument();
  });

  it("shows pending receivable/payable amounts and counts", async () => {
    renderWithProviders(<CashFlowPage />);
    // receivable money(20000) = €200.00, count 4.
    expect(await screen.findByText("€200.00")).toBeInTheDocument();
    expect(screen.getByText("4 items")).toBeInTheDocument();
    expect(screen.getByText("2 items")).toBeInTheDocument();
  });

  it("shows A/R aging buckets and total on the Receivables tab", async () => {
    renderWithProviders(<CashFlowPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("tab", { name: "Receivables" }));

    expect(await screen.findByText("Total outstanding")).toBeInTheDocument();
    // moneyObject renders the DTO's own `formatted` string (fixture helper omits separators).
    // Total + the single by-customer row both show this amount.
    expect(screen.getAllByText("€7300.00").length).toBeGreaterThan(0);
    expect(screen.getByText("31–60 days")).toBeInTheDocument();
    // current bucket money(400000).
    expect(screen.getByText("€4000.00")).toBeInTheDocument();
  });

  it("links each by-customer row to the customer detail page", async () => {
    renderWithProviders(<CashFlowPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("tab", { name: "Receivables" }));

    const link = await screen.findByRole("link", { name: "Acme Corporation" });
    expect(link).toHaveAttribute("href", "/customers/cus_1");
  });

  it("shows a permission error on 403", async () => {
    server.use(
      http.get(`${API_URL}/cash-flow`, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })),
    );
    renderWithProviders(<CashFlowPage />);
    expect(
      await screen.findByText("You don't have permission to view cash flow."),
    ).toBeInTheDocument();
  });
});
