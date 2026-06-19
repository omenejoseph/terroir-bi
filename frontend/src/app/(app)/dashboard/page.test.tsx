import { beforeEach, describe, expect, it } from "vitest";

import DashboardPage from "./page";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent } from "@/test/utils";

describe("DashboardPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("greets the user and renders the revenue summary cards from the API", async () => {
    renderWithProviders(<DashboardPage />);

    expect(await screen.findByText("Welcome back, Ada")).toBeInTheDocument();
    expect(await screen.findByText("Month-to-date")).toBeInTheDocument();
    expect(screen.getByText("Year-to-date")).toBeInTheDocument();
    // Shown by both the always-on total card and the revenue-by-channel total.
    expect(screen.getAllByText("All revenue sources").length).toBeGreaterThan(0);
  });

  it("renders the period selector and the year-over-year comparison", async () => {
    renderWithProviders(<DashboardPage />);

    // Default period is MTD; its chip is pressed.
    expect(await screen.findByRole("button", { name: "MTD" })).toHaveAttribute("aria-pressed", "true");
    // Today's revenue (12.500) vs last year (10.000) → +25.0%; YTD (24.860 vs 21.000) → +18.4%.
    expect(screen.getByText("+25.0%")).toBeInTheDocument();
    expect(screen.getByText("+18.4%")).toBeInTheDocument();
  });

  it("renders the chart sections and recent orders", async () => {
    renderWithProviders(<DashboardPage />);

    expect(await screen.findByText("Order Status")).toBeInTheDocument();
    expect(screen.getByText("Top Selling Products")).toBeInTheDocument();
    expect(screen.getByText("Stock Watch")).toBeInTheDocument();
    expect(screen.getByText("Recent Orders")).toBeInTheDocument();
    expect(screen.getByText("Acme Corporation")).toBeInTheDocument();
    // Line items show in the card (not just a count).
    expect(screen.getByText("Plavac Mali 2021")).toBeInTheDocument();
    expect(screen.getByText("Pošip 2022")).toBeInTheDocument();
    // The order's creator is shown alongside the date.
    expect(screen.getByText("Jun 8 · Ada Lovelace")).toBeInTheDocument();
  });

  it("switches the period", async () => {
    renderWithProviders(<DashboardPage />);
    const user = userEvent.setup();

    await screen.findByText("Welcome back, Ada");
    const ytd = screen.getByRole("button", { name: "YTD" });
    await user.click(ytd);
    expect(ytd).toHaveAttribute("aria-pressed", "true");
  });

  it("renders the revenue-by-channel breakdown", async () => {
    renderWithProviders(<DashboardPage />);

    expect(await screen.findByText("Revenue by channel")).toBeInTheDocument();
    // Channel labels reuse the customer-type labels.
    expect(screen.getByText("Wholesale")).toBeInTheDocument();
    expect(screen.getByText("Retail")).toBeInTheDocument();
    expect(screen.getByText("Shipshop")).toBeInTheDocument();
  });

  it("renders the reorder radar with flagged accounts", async () => {
    renderWithProviders(<DashboardPage />);

    expect(await screen.findByText("Reorder radar")).toBeInTheDocument();
    expect(screen.getByText("Vinoteka Split")).toBeInTheDocument();
    // Six flagged accounts, collapsed to five → a "Show all 6" toggle appears.
    expect(screen.getByText("Show all 6")).toBeInTheDocument();
  });

  it("reveals the custom date range panel", async () => {
    renderWithProviders(<DashboardPage />);
    const user = userEvent.setup();

    await screen.findByText("Welcome back, Ada");
    await user.click(screen.getByRole("button", { name: "Custom…" }));
    expect(screen.getByText("From")).toBeInTheDocument();
    expect(screen.getByText("To")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Apply" })).toBeInTheDocument();
  });
});
