import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import SpendPage from "./page";
import { API_URL } from "@/lib/config";
import { makeInventorySpend } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("Inventory spend page", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders summary deltas, the runout + per-product tables", async () => {
    renderWithProviders(<SpendPage />);

    expect(await screen.findByText("Inventory spend")).toBeInTheDocument();

    // Summary cards + prev-period comparison.
    expect(screen.getByText("Units exited")).toBeInTheDocument();
    expect(screen.getByText("Distinct SKUs")).toBeInTheDocument();
    expect(screen.getAllByText(/prev/).length).toBeGreaterThan(0);

    // Per-product table row.
    expect(screen.getByText("FP-REDWINE-001")).toBeInTheDocument();
    expect(screen.getAllByText("Premium Red Blend").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Red Wine").length).toBeGreaterThan(0); // subcategory header
    expect(screen.getAllByText("150").length).toBeGreaterThan(0); // on hand
  });

  it("re-queries when the range changes", async () => {
    let lastQuery: string | null = null;
    server.use(
      http.get(`${API_URL}/inventory-items/spend`, ({ request }) => {
        lastQuery = new URL(request.url).search;
        return HttpResponse.json({ data: makeInventorySpend() });
      }),
    );

    renderWithProviders(<SpendPage />);
    const user = userEvent.setup();
    await screen.findByText("Inventory spend");

    await user.click(screen.getByRole("button", { name: "YTD" }));
    await waitFor(() => expect(lastQuery).toMatch(/from=\d{4}-01-01/)); // YTD starts Jan 1
  });
});
