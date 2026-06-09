import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import InventoryPage from "./page";
import { API_URL } from "@/lib/config";
import { makeItem, makeSession } from "@/test/fixtures";
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

async function openAddDialog() {
  const user = userEvent.setup();
  await user.click(await screen.findByRole("button", { name: /Add item/ }));
  await screen.findByText("Add inventory item");
  return user;
}

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

    // Wait for the list so the page (and auth) have settled.
    await screen.findAllByText("Plavac Mali 2021");
    expect(screen.queryByRole("button", { name: /Add item/ })).not.toBeInTheDocument();
  });

  it("creates an item with the entered values and closes the dialog", async () => {
    seedAuth();

    let captured: Record<string, unknown> | null = null;
    server.use(
      http.post(itemsUrl, async ({ request }) => {
        captured = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeItem({ id: "itm_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<InventoryPage />);
    const user = await openAddDialog();

    await user.type(screen.getByLabelText("Name"), "Test Wine");
    await user.type(screen.getByLabelText("SKU"), "TW-1");
    await user.selectOptions(screen.getByLabelText("Unit"), "case");
    await user.selectOptions(screen.getByLabelText("Sales unit"), "cases");
    await user.type(screen.getByLabelText("Default price (per sales unit)"), "150");
    await user.type(screen.getByLabelText("Cost per sales unit"), "7");
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

    // Dialog closes on success.
    await waitFor(() =>
      expect(screen.queryByText("Add inventory item")).not.toBeInTheDocument(),
    );
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

    renderWithProviders(<InventoryPage />);
    const user = await openAddDialog();

    await user.type(screen.getByLabelText("Name"), "Opening Wine");
    await user.type(screen.getByLabelText("SKU"), "OW-1");
    await user.type(screen.getByLabelText("Opening stock (optional)"), "25");
    await user.type(screen.getByLabelText("Cost per sales unit"), "7");
    await user.click(screen.getByRole("button", { name: "Create item" }));

    await waitFor(() => expect(stockBody).not.toBeNull());
    expect(stockId).toBe("itm_new");
    expect(stockBody).toMatchObject({ type: "MANUAL_IN", quantity: 25 });
  });

  it("creates a new group via the combobox and includes it in the payload", async () => {
    seedAuth();

    let captured: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/inventory-items`, async ({ request }) => {
        captured = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeItem({ id: "itm_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<InventoryPage />);
    const user = await openAddDialog();

    await user.type(screen.getByLabelText("Name"), "Pinot Noir");
    await user.type(screen.getByLabelText("SKU"), "PN-1");

    // Open the group combobox, type a new value, and create it.
    await user.click(screen.getByText("e.g. Wine"));
    await user.type(screen.getByPlaceholderText("e.g. Wine"), "Wine");
    await user.click(screen.getByRole("button", { name: /Create "Wine"/ }));

    await user.type(screen.getByLabelText("Cost per sales unit"), "7");

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

    renderWithProviders(<InventoryPage />);
    const user = await openAddDialog();

    await user.type(screen.getByLabelText("Name"), "Dup");
    await user.type(screen.getByLabelText("SKU"), "PM-2021");
    // Unit defaults to "bottle" from the dropdown — no interaction needed.
    await user.type(screen.getByLabelText("Cost per sales unit"), "7");
    await user.click(screen.getByRole("button", { name: "Create item" }));

    expect(await screen.findByText("The sku has already been taken.")).toBeInTheDocument();
    // Dialog stays open so the user can correct the error.
    expect(screen.getByText("Add inventory item")).toBeInTheDocument();
  });
});