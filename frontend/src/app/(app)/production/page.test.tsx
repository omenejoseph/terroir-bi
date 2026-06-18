import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import ProductionPage from "./page";
import { API_URL } from "@/lib/config";
import { makeItem } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

const plan = {
  id: "plan_1",
  name: "Spring bottling",
  status: "DRAFT" as const,
  confirmed_at: null,
  notes: null,
  rows_count: 1,
  rows: [
    {
      id: "row_1",
      base_item_id: "item_1",
      base_item_name: "Plavac Mali",
      new_vintage: "2024",
      created_item_id: null,
      quantity: "100",
      plan_unit: "cases" as const,
      sort_order: 0,
    },
  ],
};

describe("ProductionPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
    server.use(
      http.get(`${API_URL}/production-plans`, () =>
        HttpResponse.json({ data: [{ ...plan, rows: undefined }] }),
      ),
      http.get(`${API_URL}/production-plans/plan_1`, () => HttpResponse.json({ data: plan })),
      http.get(`${API_URL}/inventory-items`, () =>
        HttpResponse.json({ data: [makeItem({ id: "item_1", name: "Plavac Mali" })] }),
      ),
      http.get(`${API_URL}/production-plans/plan_1/calculate`, () =>
        HttpResponse.json({
          data: {
            rows: [
              {
                row_id: "row_1",
                base_item_id: "item_1",
                name: "Plavac Mali",
                quantity: "100",
                plan_unit: "cases",
                bottles: 600,
                new_vintage: "2024",
                revenue: 1_200_000,
                cost: 480_000,
                margin_pct: 60,
              },
            ],
            materials: [{ name: "Cork", unit: "pcs", quantity: 600, cost: 30_000 }],
            totals: { revenue: 1_200_000, cost: 480_000, margin_pct: 60 },
          },
        }),
      ),
    );
  });

  it("lists plans and calculates margins on demand", async () => {
    renderWithProviders(<ProductionPage />);
    const user = userEvent.setup();

    // The plan and its row load.
    await waitFor(() => expect(screen.getByText("Spring bottling")).toBeInTheDocument());
    await screen.findByDisplayValue("100");

    // Calculate pulls revenue/cost/margin.
    await user.click(screen.getByRole("button", { name: "Calculate" }));
    // Totals + per-row margin both render 60%.
    await waitFor(() => expect(screen.getAllByText("60%").length).toBeGreaterThan(0));
    expect(screen.getByText("Cork")).toBeInTheDocument();
  });
});
