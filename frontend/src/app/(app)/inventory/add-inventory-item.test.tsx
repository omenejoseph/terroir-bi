import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import InventoryPage from "./page";
import NewInventoryItemPage from "./new/page";
import { API_URL } from "@/lib/config";
import { makeItem, makeSession } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

const itemsUrl = `${API_URL}/inventory-items`;

describe("Inventory — add item", () => {
  beforeEach(() => seedLocale("en"));

  it("shows the Add button for a user who can manage inventory", async () => {
    seedAuth(); // session roles default to ADMIN (grants inventory.manage)
    renderWithProviders(<InventoryPage />);

    expect(await screen.findByRole("button", { name: /Add item/ })).toBeInTheDocument();
  });

  it("hides the Add button for a user without permission", async () => {
    seedAuth();
    server.use(
      http.get(`${API_URL}/auth/me`, () =>
        HttpResponse.json({ data: makeSession({ roles: ["CELLAR"] }) }),
      ),
    );

    renderWithProviders(<InventoryPage />);

    await screen.findAllByText("Plavac Mali 2021");
    expect(screen.queryByRole("button", { name: /Add item/ })).not.toBeInTheDocument();
  });

  it("creates an item with the entered values and returns to the list", async () => {
    seedAuth();

    let captured: Record<string, unknown> | null = null;
    server.use(
      http.post(itemsUrl, async ({ request }) => {
        captured = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeItem({ id: "itm_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewInventoryItemPage />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText("Name"), "Test Wine");
    await user.type(screen.getByLabelText("SKU"), "TW-1");
    await user.selectOptions(screen.getByLabelText("Unit"), "case");
    await user.selectOptions(screen.getByLabelText("Sales unit"), "cases");
    // Wine is priced per bottle regardless of the bottle/case unit.
    await user.type(screen.getByLabelText("Default price (per Bottle)"), "150");
    await user.type(screen.getByLabelText("Cost per Bottle"), "7");
    await user.click(screen.getByRole("button", { name: "Create item" }));

    await waitFor(() => expect(captured).not.toBeNull());
    expect(captured).toMatchObject({
      name: "Test Wine",
      sku: "TW-1",
      unit: "case",
      category: "FINISHED",
      sales_unit: "cases",
      bottles_per_case: 12,
      // Entered in major units → sent as integer minor units.
      default_price: 15000,
      cost_per_unit: 700,
    });
    await waitFor(() => expect(mockRouter.push).toHaveBeenCalledWith("/inventory"));
  });

  it("creates an item without a cost (COGS can come from a recipe)", async () => {
    seedAuth();

    let captured: Record<string, unknown> | null = null;
    server.use(
      http.post(itemsUrl, async ({ request }) => {
        captured = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeItem({ id: "itm_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewInventoryItemPage />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText("Name"), "No Cost Wine");
    await user.type(screen.getByLabelText("SKU"), "NC-1");
    // Cost left blank.
    await user.click(screen.getByRole("button", { name: "Create item" }));

    await waitFor(() => expect(captured).not.toBeNull());
    expect(captured).toMatchObject({ name: "No Cost Wine", sku: "NC-1" });
    expect(captured!.cost_per_unit).toBeNull();
  });

  it("hides sales unit / bottles-per-case for non-packaged units", async () => {
    seedAuth();

    let captured: Record<string, unknown> | null = null;
    server.use(
      http.post(itemsUrl, async ({ request }) => {
        captured = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeItem({ id: "itm_bulk" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewInventoryItemPage />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText("Name"), "Bulk Plavac");
    await user.type(screen.getByLabelText("SKU"), "BULK-1");
    await user.selectOptions(screen.getByLabelText("Unit"), "liter");

    // Wine-only fields disappear; price/cost are now per the chosen unit.
    expect(screen.queryByLabelText("Sales unit")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("Bottles per case")).not.toBeInTheDocument();
    await user.type(screen.getByLabelText("Cost per Litre"), "3");
    await user.click(screen.getByRole("button", { name: "Create item" }));

    await waitFor(() => expect(captured).not.toBeNull());
    expect(captured).toMatchObject({ unit: "liter", cost_per_unit: 300 });
    expect(captured).not.toHaveProperty("sales_unit");
    expect(captured).not.toHaveProperty("bottles_per_case");
  });

  it("posts opening stock as a MANUAL_IN movement after creating", async () => {
    seedAuth();

    let stockId: string | null = null;
    let stockBody: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/inventory-items/:id/stock`, async ({ request, params }) => {
        stockId = String(params.id);
        stockBody = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeItem({ id: "itm_new", current_stock: "25" }) });
      }),
    );

    renderWithProviders(<NewInventoryItemPage />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText("Name"), "Opening Wine");
    await user.type(screen.getByLabelText("SKU"), "OW-1");
    await user.type(screen.getByLabelText("Opening stock (optional)"), "25");
    await user.type(screen.getByLabelText("Cost per Bottle"), "7");
    await user.click(screen.getByRole("button", { name: "Create item" }));

    await waitFor(() => expect(stockBody).not.toBeNull());
    expect(stockId).toBe("itm_new");
    expect(stockBody).toMatchObject({ type: "MANUAL_IN", quantity: 25 });
  });

  it("creates a new group via the combobox and includes it in the payload", async () => {
    seedAuth();

    let captured: Record<string, unknown> | null = null;
    server.use(
      http.post(itemsUrl, async ({ request }) => {
        captured = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeItem({ id: "itm_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewInventoryItemPage />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText("Name"), "Pinot Noir");
    await user.type(screen.getByLabelText("SKU"), "PN-1");

    await user.click(screen.getByText("e.g. Wine"));
    await user.type(screen.getByPlaceholderText("e.g. Wine"), "Wine");
    await user.click(screen.getByRole("button", { name: /Create "Wine"/ }));

    await user.type(screen.getByLabelText("Cost per Bottle"), "7");
    await user.click(screen.getByRole("button", { name: "Create item" }));

    await waitFor(() => expect(captured).not.toBeNull());
    expect(captured).toMatchObject({ name: "Pinot Noir", sku: "PN-1", group: "Wine" });
  });

  it("surfaces server validation errors (422) under the fields", async () => {
    seedAuth();
    server.use(
      http.post(itemsUrl, () =>
        HttpResponse.json(
          { message: "The given data was invalid.", errors: { sku: ["The sku has already been taken."] } },
          { status: 422 },
        ),
      ),
    );

    renderWithProviders(<NewInventoryItemPage />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText("Name"), "Dup");
    await user.type(screen.getByLabelText("SKU"), "PM-2021");
    await user.type(screen.getByLabelText("Cost per Bottle"), "7");
    await user.click(screen.getByRole("button", { name: "Create item" }));

    expect(await screen.findByText("The sku has already been taken.")).toBeInTheDocument();
    // Stays on the page so the user can correct the error.
    expect(mockRouter.push).not.toHaveBeenCalledWith("/inventory");
  });
});
