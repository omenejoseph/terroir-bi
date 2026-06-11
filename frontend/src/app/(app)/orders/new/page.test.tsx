import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import NewOrderPage from "./page";
import { API_URL } from "@/lib/config";
import { makeItem, makeOrder } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("NewOrderPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("creates an order with a catalog and a custom line", async () => {
    let body:
      | { customer_id?: string; items?: { unit_price?: number }[]; shipping_cost?: number; status?: string }
      | null = null;
    server.use(
      http.post(`${API_URL}/orders`, async ({ request }) => {
        body = (await request.json()) as {
          customer_id?: string;
          items?: { unit_price?: number }[];
          shipping_cost?: number;
          status?: string;
        };
        return HttpResponse.json({ data: makeOrder({ id: "ord_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewOrderPage />);
    const user = userEvent.setup();

    // Pick a customer.
    await user.click(await screen.findByText("Select a customer…"));
    await user.click(await screen.findByText(/Acme Corporation/));

    // Fill the default catalog line — the item picker only offers for-sale items.
    let itemQuery: string | null = null;
    server.use(
      http.get(`${API_URL}/inventory-items`, ({ request }) => {
        itemQuery = new URL(request.url).search;
        return HttpResponse.json({
          data: [makeItem({ id: "itm_1", name: "Plavac Mali 2021" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );
    await user.click(screen.getByText("Select an item…"));
    await user.click(await screen.findByText(/Plavac Mali 2021/));
    expect(itemQuery).toMatch(/is_for_sale=true/);

    // Add a custom line (price entered in major units → €5.00).
    await user.click(screen.getByRole("button", { name: /Add custom line/ }));
    await user.type(screen.getByPlaceholderText("Custom line description"), "Gift wrapping");
    await user.type(screen.getByLabelText("Unit price"), "5");

    // Logistics (we pay) is in major units (€10) and sent as minor (1000).
    await user.type(screen.getByLabelText("Logistics (we pay)"), "10");
    // Pick a non-default initial status.
    await user.selectOptions(screen.getByLabelText("Initial status"), "IN_PROCESS");

    await user.click(screen.getByRole("button", { name: "Create order" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.customer_id).toBe("cus_1");
    expect(Array.isArray(body!.items)).toBe(true);
    expect(body!.items!.length).toBe(2);
    expect(body!.shipping_cost).toBe(1000);
    expect(body!.status).toBe("IN_PROCESS");
    // Custom line price converted major → minor (€5.00 → 500).
    expect(body!.items!.find((i) => i.unit_price === 500)).toBeTruthy();
    await waitFor(() => expect(mockRouter.push).toHaveBeenCalledWith("/orders/ord_new"));
  });

  it("shows the customer-resolved price and the line total", async () => {
    server.use(
      http.get(`${API_URL}/inventory-items`, () =>
        HttpResponse.json({
          data: [makeItem({ id: "itm_1", name: "Plavac Mali 2021" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        }),
      ),
      // The customer resolves to €15.00/bottle (tier/rebate/custom — handled server-side).
      http.get(`${API_URL}/customers/:id/resolved-prices`, () =>
        HttpResponse.json({ data: { itm_1: { minor: 1500, currency: "EUR" } } }),
      ),
    );

    renderWithProviders(<NewOrderPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByText("Select a customer…"));
    await user.click(await screen.findByText(/Acme Corporation/));
    await user.click(screen.getByText("Select an item…"));
    await user.click(await screen.findByText(/Plavac Mali 2021/));

    // Unit price "ea." + line total (qty 1) reflect the resolved €15.00.
    expect(await screen.findByText(/15,00 € ea\./)).toBeInTheDocument();
    // Bump quantity to 3 → line total and order total both become €45.00.
    const qty = screen.getByLabelText("Qty");
    await user.clear(qty);
    await user.type(qty, "3");
    await waitFor(() => expect(screen.getAllByText("45,00 €").length).toBeGreaterThanOrEqual(2));
  });

  it("makes a backorder that opts in to deducting stock", async () => {
    let body: { is_backorder?: boolean; deduct_stock?: boolean; is_consignment?: boolean } | null = null;
    server.use(
      http.get(`${API_URL}/inventory-items`, () =>
        HttpResponse.json({
          data: [makeItem({ id: "itm_1", name: "Plavac Mali 2021" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        }),
      ),
      http.post(`${API_URL}/orders`, async ({ request }) => {
        body = (await request.json()) as typeof body;
        return HttpResponse.json({ data: makeOrder({ id: "ord_bo" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewOrderPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Select a customer…"));
    await user.click(await screen.findByText(/Acme Corporation/));
    await user.click(screen.getByText("Select an item…"));
    await user.click(await screen.findByText(/Plavac Mali 2021/));

    // Pick the Backorder fulfilment option, then opt in to deduct stock now.
    await user.click(screen.getByLabelText("Backorder"));
    await user.click(screen.getByRole("checkbox", { name: /Deduct stock now/ }));
    await user.click(screen.getByRole("button", { name: "Create order" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.is_backorder).toBe(true);
    expect(body!.deduct_stock).toBe(true);
    expect(body!.is_consignment).toBe(false);
  });

  it("marks a catalog line as a gift (zero price)", async () => {
    let body: { items?: { unit_price?: number }[] } | null = null;
    server.use(
      http.get(`${API_URL}/inventory-items`, () =>
        HttpResponse.json({
          data: [makeItem({ id: "itm_1", name: "Plavac Mali 2021" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        }),
      ),
      http.post(`${API_URL}/orders`, async ({ request }) => {
        body = (await request.json()) as typeof body;
        return HttpResponse.json({ data: makeOrder({ id: "ord_gift" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewOrderPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByText("Select a customer…"));
    await user.click(await screen.findByText(/Acme Corporation/));
    await user.click(screen.getByText("Select an item…"));
    await user.click(await screen.findByText(/Plavac Mali 2021/));

    // Toggle the gift checkbox → the price input is forced to 0 and disabled.
    await user.click(screen.getByLabelText("Gift"));
    expect(screen.getByLabelText("Price override")).toBeDisabled();

    await user.click(screen.getByRole("button", { name: "Create order" }));
    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.items![0].unit_price).toBe(0);
  });

  it("validates that a customer and items are required", async () => {
    renderWithProviders(<NewOrderPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Create order" }));
    expect(await screen.findByText("Pick a customer.")).toBeInTheDocument();
  });

  it("surfaces a server 422 validation error", async () => {
    server.use(
      http.post(`${API_URL}/orders`, () =>
        HttpResponse.json(
          { message: "The items field is required.", errors: { items: ["At least one item is required."] } },
          { status: 422 },
        ),
      ),
    );

    renderWithProviders(<NewOrderPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByText("Select a customer…"));
    await user.click(await screen.findByText(/Acme Corporation/));
    await user.click(screen.getByText("Select an item…"));
    const matches = await screen.findAllByText(/Plavac Mali 2021/);
    await user.click(matches[matches.length - 1]);
    await user.click(screen.getByRole("button", { name: "Create order" }));

    expect(await screen.findByText("At least one item is required.")).toBeInTheDocument();
  });
});
