import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import CustomerDetailPage from "./page";
import { API_URL } from "@/lib/config";
import { makeCustomer, makeCustomerOrderAnalytics } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
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

// useParams is mocked in setup to return { id: "itm_1" }.

describe("CustomerDetailPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("loads the customer and saves edits", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/customers/:id`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeCustomer({ id: String(params.id) }) });
      }),
    );

    renderWithProviders(<CustomerDetailPage />);
    const user = userEvent.setup();

    const name = await screen.findByLabelText("Company name");
    expect((name as HTMLInputElement).value).toBe("Acme Corporation");

    await user.clear(name);
    await user.type(name, "Acme Renamed");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ company_name: "Acme Renamed" });
    expect(mockRouter.push).toHaveBeenCalledWith("/customers");
  });

  it("deletes the customer (admin) and returns to the list", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/customers/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<CustomerDetailPage />);
    const user = userEvent.setup();

    // Click the form's Delete, then confirm in the dialog.
    await user.click(await screen.findByRole("button", { name: /Delete/ }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /Delete/ }));

    await waitFor(() => expect(deleted).toBe(true));
    expect(mockRouter.push).toHaveBeenCalledWith("/customers");
  });

  it("aborts deletion when the confirmation is cancelled", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/customers/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<CustomerDetailPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: /Delete/ }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Cancel" }));

    expect(deleted).toBe(false);
  });

  it("shows revenue summary cards", async () => {
    renderWithProviders(<CustomerDetailPage />);
    expect(await screen.findByText("Total revenue")).toBeInTheDocument();
    expect(await screen.findByText("€1200.00")).toBeInTheDocument(); // total_revenue
    expect(screen.getByText("This year")).toBeInTheDocument();
  });

  it("shows 'Never' when the customer has never ordered", async () => {
    server.use(
      http.get(`${API_URL}/customers/:id/order-analytics`, () =>
        HttpResponse.json({
          data: makeCustomerOrderAnalytics({ last_order_date: null, total_revenue: { minor: 0, currency: "EUR", formatted: "€0.00" } }),
        }),
      ),
    );
    renderWithProviders(<CustomerDetailPage />);
    expect(await screen.findByText("Never")).toBeInTheDocument();
  });

  it("Orders tab shows analytics + order history", async () => {
    renderWithProviders(<CustomerDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("Acme Corporation");
    await user.click(screen.getByRole("tab", { name: "Orders" }));

    expect(await screen.findByText("Annual projection")).toBeInTheDocument();
    expect(await screen.findByText("ORD-1001")).toBeInTheDocument(); // history
  });

  it("Custom pricing tab sets a per-product price (overrides rebate)", async () => {
    let putBody: { price?: number } | null = null;
    let putUrl = "";
    server.use(
      http.put(`${API_URL}/inventory-items/:item/customer-price/:customer`, async ({ request }) => {
        putUrl = request.url;
        putBody = (await request.json()) as { price?: number };
        return HttpResponse.json({ data: { minor: 1200, currency: "EUR", formatted: "€12.00" } });
      }),
    );

    renderWithProviders(<CustomerDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("Acme Corporation");
    await user.click(screen.getByRole("tab", { name: "Custom pricing" }));

    expect(await screen.findByText(/override any rebate/i)).toBeInTheDocument();
    await user.click(await screen.findByLabelText("Product"));
    await user.type(screen.getByPlaceholderText("Search products…"), "Plavac");
    await user.click(await screen.findByRole("button", { name: /Plavac Mali 2021/ }));
    await user.type(screen.getByLabelText("Price"), "12");
    await user.click(screen.getByRole("button", { name: "Set price" }));

    await waitFor(() => expect(putBody).not.toBeNull());
    expect(putBody).toMatchObject({ price: 1200 }); // major 12 → minor 1200
    expect(putUrl).toContain("/customer-price/");
  });

  it("generates a self-service order link", async () => {
    let generated = false;
    server.use(
      http.post(`${API_URL}/customers/:id/order-token`, ({ params }) => {
        generated = true;
        return HttpResponse.json({
          data: { ...makeCustomer({ id: String(params.id), has_order_token: true }), order_token: "tok_x" },
        });
      }),
    );

    renderWithProviders(<CustomerDetailPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Generate link" }));
    await waitFor(() => expect(generated).toBe(true));
  });
});