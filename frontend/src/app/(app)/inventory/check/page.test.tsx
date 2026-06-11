import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import CheckPage from "./page";
import { API_URL } from "@/lib/config";
import { makeItem } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
  within,
} from "@/test/utils";

describe("Inventory check page", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
    server.use(
      http.get(`${API_URL}/inventory-items`, () =>
        HttpResponse.json({
          data: [makeItem({ id: "itm_1", name: "Premium Red Blend", current_stock: "100", unit: "bottles" })],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        }),
      ),
    );
  });

  it("computes the difference and applies a stocktake", async () => {
    let body: { items: { item_id: string; physical_count: number }[] } | null = null;
    server.use(
      http.post(`${API_URL}/inventory-items/check`, async ({ request }) => {
        body = (await request.json()) as typeof body;
        return HttpResponse.json({ data: [{ item_id: "itm_1", difference: "-10.000" }] });
      }),
    );

    renderWithProviders(<CheckPage />);
    const user = userEvent.setup();

    await screen.findByText("Finished Products");
    const input = screen.getByLabelText("Premium Red Blend physical count");
    await user.type(input, "90");

    // Difference shows and Apply reflects one pending change.
    expect(screen.getByText("-10")).toBeInTheDocument();
    const apply = screen.getByRole("button", { name: /Apply \(1\)/ });
    await user.click(apply);

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.items).toEqual([{ item_id: "itm_1", physical_count: 90 }]);
    expect(await screen.findByText("1 stock adjustments applied successfully.")).toBeInTheDocument();
  });

  it("filters by category (re-queries)", async () => {
    let lastCategory: string | null = null;
    server.use(
      http.get(`${API_URL}/inventory-items`, ({ request }) => {
        lastCategory = new URL(request.url).searchParams.get("category");
        return HttpResponse.json({
          data: [makeItem({ id: "itm_1", name: "Premium Red Blend", current_stock: "100" })],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        });
      }),
    );

    renderWithProviders(<CheckPage />);
    const user = userEvent.setup();
    await screen.findByText("Finished Products");

    await user.click(screen.getByRole("button", { name: "Semi-finished" }));
    await waitFor(() => expect(lastCategory).toBe("SEMI_FINISHED"));
  });

  it("shows the audit history with its adjusted lines", async () => {
    renderWithProviders(<CheckPage />);
    const user = userEvent.setup();

    await user.click(screen.getByRole("button", { name: "History" }));
    expect(await screen.findByText("Ada Lovelace")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "View" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("FP-REDWINE-001")).toBeInTheDocument();
    expect(within(dialog).getByText("-10.000")).toBeInTheDocument();
  });
});
