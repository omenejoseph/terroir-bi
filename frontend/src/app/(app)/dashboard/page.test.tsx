import { beforeEach, describe, expect, it } from "vitest";

import DashboardPage from "./page";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent } from "@/test/utils";

describe("DashboardPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("greets the user and renders the summary cards from the API", async () => {
    renderWithProviders(<DashboardPage />);

    expect(await screen.findByText("Welcome back, Ada")).toBeInTheDocument();
    expect(await screen.findByText("Total Orders")).toBeInTheDocument();
    expect(screen.getByText("Customers")).toBeInTheDocument();
    expect(screen.getByText("Low Stock")).toBeInTheDocument();
    expect(screen.getAllByText("Revenue").length).toBeGreaterThan(0);
  });

  it("renders the outstanding A/R and overdue task stat cards", async () => {
    renderWithProviders(<DashboardPage />);

    expect(await screen.findByText("Outstanding A/R")).toBeInTheDocument();
    expect(screen.getByText("2.500 €")).toBeInTheDocument();
    expect(screen.getByText("Overdue tasks")).toBeInTheDocument();
    // tasks_overdue fixture value is 3 (low_stock is also 3, so expect both).
    expect(screen.getAllByText("3").length).toBeGreaterThanOrEqual(2);
  });

  it("renders the chart sections and recent orders", async () => {
    renderWithProviders(<DashboardPage />);

    expect(await screen.findByText("Order Status")).toBeInTheDocument();
    expect(screen.getByText("Top Selling Products")).toBeInTheDocument();
    expect(screen.getByText("Stock Watch")).toBeInTheDocument();
    expect(screen.getByText("Recent Orders")).toBeInTheDocument();
    expect(screen.getByText("Acme Corporation")).toBeInTheDocument();
  });

  it("switches the time range", async () => {
    renderWithProviders(<DashboardPage />);
    const user = userEvent.setup();

    await screen.findByText("Welcome back, Ada");
    await user.click(screen.getByRole("tab", { name: "7D" }));
    expect(screen.getByRole("tab", { name: "7D" })).toHaveAttribute("aria-selected", "true");
  });
});