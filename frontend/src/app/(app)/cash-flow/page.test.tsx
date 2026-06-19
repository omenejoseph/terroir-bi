import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import CashFlowPage from "./page";
import { API_URL } from "@/lib/config";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";
import { makeCashFlowAnalysis } from "@/test/fixtures";
import { server } from "@/test/mocks/server";

describe("CashFlowPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the cash-movement KPI cards with trends", async () => {
    renderWithProviders(<CashFlowPage />);
    expect(await screen.findByText("Cash Flow Analysis")).toBeInTheDocument();
    expect(screen.getByText("Cash In")).toBeInTheDocument();
    expect(screen.getByText("Cash Out")).toBeInTheDocument();
    expect(screen.getByText("Net Cash Flow")).toBeInTheDocument();
    expect(screen.getByText("Monthly Burn Rate")).toBeInTheDocument();
    // cash_in 120000 = 1.200,00 € (also the Wine-sales category row); net is signed.
    expect(screen.getAllByText("1.200,00 €").length).toBeGreaterThan(0);
    expect(screen.getByText("+500,00 €")).toBeInTheDocument(); // net 50000 with + sign
    expect(screen.getByText("+33.3%")).toBeInTheDocument(); // cash-in trend
  });

  it("shows outstanding balances + working capital", async () => {
    renderWithProviders(<CashFlowPage />);
    expect(await screen.findByText("Outstanding Receivables")).toBeInTheDocument();
    expect(screen.getByText("Outstanding Payables")).toBeInTheDocument();
    expect(screen.getByText("Net Working Capital")).toBeInTheDocument();
    expect(screen.getByText("3 pending · money owed to us")).toBeInTheDocument();
  });

  it("renders payment discipline + outstanding tables", async () => {
    renderWithProviders(<CashFlowPage />);
    expect(await screen.findByText("Payment Discipline")).toBeInTheDocument();
    expect(screen.getByText("Top Receivables")).toBeInTheDocument();
    expect(screen.getByText("Top Payables")).toBeInTheDocument();
    expect(screen.getByText("Konzum")).toBeInTheDocument(); // a receivable counterparty
    expect(screen.getByText("Staklo d.o.o.")).toBeInTheDocument(); // a payable counterparty
  });

  it("renders the forecast summary + pending pipeline cards", async () => {
    renderWithProviders(<CashFlowPage />);
    expect(await screen.findByText("Avg monthly revenue")).toBeInTheDocument();
    expect(screen.getByText("Avg monthly costs")).toBeInTheDocument();
    expect(screen.getByText("Revenue growth")).toBeInTheDocument();
    expect(screen.getByText("+4.00%")).toBeInTheDocument(); // revenue_growth_percent, signed
    // Pending pipeline counts (receivable_count 4, payable_count 2).
    expect(screen.getByText("4 items")).toBeInTheDocument();
    expect(screen.getByText("2 items")).toBeInTheDocument();
  });

  it("renders the outflow-by-category breakdown with a total", async () => {
    renderWithProviders(<CashFlowPage />);
    expect(await screen.findByText("Outflow by Category")).toBeInTheDocument();
    // Operations appears in both the cash-out card and the breakdown.
    expect(screen.getAllByText("Operations").length).toBeGreaterThan(0);
  });

  it("sends a date range when a preset is chosen", async () => {
    const ranges: { from: string | null; to: string | null }[] = [];
    server.use(
      http.get(`${API_URL}/cash-flow/analysis`, ({ request }) => {
        const u = new URL(request.url).searchParams;
        ranges.push({ from: u.get("from"), to: u.get("to") });
        return HttpResponse.json({ data: makeCashFlowAnalysis() });
      }),
    );

    renderWithProviders(<CashFlowPage />);
    const user = userEvent.setup();
    await screen.findByText("Cash Flow Analysis");
    await user.click(screen.getByRole("button", { name: "This Month" }));
    await waitFor(() => expect(ranges.at(-1)?.from).toMatch(/-01$/));
  });

  it("shows a permission error on 403", async () => {
    server.use(
      http.get(`${API_URL}/cash-flow/analysis`, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })),
    );
    renderWithProviders(<CashFlowPage />);
    expect(
      await screen.findByText("You don't have permission to view cash flow."),
    ).toBeInTheDocument();
  });
});
