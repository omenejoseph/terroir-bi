import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import NewOrderPage from "./page";
import { API_URL } from "@/lib/config";
import { makeOrder } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("NewOrderPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("creates an order with a catalog and a custom line", async () => {
    let body: { customer_id?: string; items?: unknown[] } | null = null;
    server.use(
      http.post(`${API_URL}/orders`, async ({ request }) => {
        body = (await request.json()) as { customer_id?: string; items?: unknown[] };
        return HttpResponse.json({ data: makeOrder({ id: "ord_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewOrderPage />);
    const user = userEvent.setup();

    // Pick a customer.
    await user.click(await screen.findByText("Select a customer…"));
    await user.click(await screen.findByText(/Acme Corporation/));

    // Fill the default catalog line.
    await user.click(screen.getByText("Select an item…"));
    await user.click(await screen.findByText(/Plavac Mali 2021/));

    // Add a custom line.
    await user.click(screen.getByRole("button", { name: /Add custom line/ }));
    await user.type(screen.getByPlaceholderText("Custom line description"), "Gift wrapping");
    await user.type(screen.getByLabelText("Unit price"), "500");

    await user.click(screen.getByRole("button", { name: "Create order" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.customer_id).toBe("cus_1");
    expect(Array.isArray(body!.items)).toBe(true);
    expect(body!.items!.length).toBe(2);
    await waitFor(() => expect(mockRouter.push).toHaveBeenCalledWith("/orders/ord_new"));
  });

  it("validates that a customer and items are required", async () => {
    renderWithProviders(<NewOrderPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Create order" }));
    expect(await screen.findByText("Pick a customer.")).toBeInTheDocument();
  });
});
