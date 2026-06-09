import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import InventoryPage from "./page";
import { API_URL } from "@/lib/config";
import { makeItem } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

const url = `${API_URL}/inventory-items`;

describe("InventoryPage", () => {
  beforeEach(() => seedLocale("en"));

  it("renders items returned by the API", async () => {
    renderWithProviders(<InventoryPage />);

    // Rendered in both the mobile card list and the desktop table.
    expect((await screen.findAllByText("Plavac Mali 2021")).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Graševina 2022").length).toBeGreaterThan(0);
  });

  it("filters the list via the search box (debounced, re-queries the API)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<InventoryPage />);

    await screen.findAllByText("Plavac Mali 2021");
    await user.type(
      screen.getByPlaceholderText("Search by name or SKU…"),
      "Graševina",
    );

    // Wait for the settled, filtered state: only Graševina remains.
    await waitFor(() => {
      expect(screen.queryAllByText("Plavac Mali 2021")).toHaveLength(0);
      expect(screen.getAllByText("Graševina 2022").length).toBeGreaterThan(0);
    });
  });

  it("shows an empty state when there are no items", async () => {
    server.use(
      http.get(url, () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
        }),
      ),
    );

    renderWithProviders(<InventoryPage />);
    expect(await screen.findByText("No items found.")).toBeInTheDocument();
  });

  it("shows a permission error on a 403", async () => {
    server.use(
      http.get(url, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })),
    );

    renderWithProviders(<InventoryPage />);
    expect(
      await screen.findByText("You don't have permission to view inventory for this tenant."),
    ).toBeInTheDocument();
  });

  it("shows a generic error on a 500", async () => {
    server.use(
      http.get(url, () => HttpResponse.json({ message: "Boom." }, { status: 500 })),
    );

    renderWithProviders(<InventoryPage />);
    expect(await screen.findByText("Failed to load inventory.")).toBeInTheDocument();
  });

  it("groups items by group and subcategory", async () => {
    server.use(
      http.get(url, () =>
        HttpResponse.json({
          data: [
            makeItem({ id: "a", name: "Plavac", sku: "A", group: "Wine", subcategory: "Red" }),
            makeItem({ id: "b", name: "Cork", sku: "B", group: "Wine", subcategory: "Packaging" }),
            makeItem({ id: "c", name: "Misc", sku: "C", group: null, subcategory: null }),
          ],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 3 },
        }),
      ),
    );

    renderWithProviders(<InventoryPage />);

    expect(await screen.findByText("Wine")).toBeInTheDocument();
    expect(screen.getByText("Red")).toBeInTheDocument();
    expect(screen.getByText("Packaging")).toBeInTheDocument();
    expect(screen.getByText("Ungrouped")).toBeInTheDocument();
  });

  it("filters by category when a tab is selected", async () => {
    let lastCategory: string | null = "unset";
    server.use(
      http.get(url, ({ request }) => {
        lastCategory = new URL(request.url).searchParams.get("category");
        return HttpResponse.json({
          data: [makeItem({ name: lastCategory ?? "ALL" })],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        });
      }),
    );

    renderWithProviders(<InventoryPage />);
    const user = userEvent.setup();

    // Initial load has no category filter.
    expect(await screen.findByText("ALL")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Raw material" }));

    expect(await screen.findByText("RAW_MATERIAL")).toBeInTheDocument();
    expect(lastCategory).toBe("RAW_MATERIAL");
  });
});