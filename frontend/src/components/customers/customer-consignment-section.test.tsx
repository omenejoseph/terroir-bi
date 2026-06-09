import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { CustomerConsignmentSection } from "./customer-consignment-section";
import { API_URL } from "@/lib/config";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

const summary = {
  products: [
    { inventory_item_id: "itm_1", name: "Plavac Mali 2021", placed: 12, sold: 4, returned: 0, remaining: 8 },
  ],
  placements: [
    { order_id: "ord_1", order_number: "ORD-1001", placed_at: "2026-06-01T10:00:00+00:00", closed_at: null },
  ],
};

describe("CustomerConsignmentSection", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
    server.use(
      http.get(`${API_URL}/customers/:id/consignment`, () => HttpResponse.json({ data: summary })),
    );
  });

  it("renders the rollup", async () => {
    renderWithProviders(<CustomerConsignmentSection customerId="cus_1" />);
    expect(await screen.findByText("Plavac Mali 2021")).toBeInTheDocument();
    expect(screen.getByText(/ORD-1001/)).toBeInTheDocument();
  });

  it("places consignment stock", async () => {
    let body: { items?: { inventory_item_id: string; quantity: number }[] } | null = null;
    server.use(
      http.post(`${API_URL}/customers/:id/consignment/place`, async ({ request }) => {
        body = (await request.json()) as { items?: { inventory_item_id: string; quantity: number }[] };
        return HttpResponse.json({ data: { order_number: "ORD-1003" } }, { status: 201 });
      }),
    );

    renderWithProviders(<CustomerConsignmentSection customerId="cus_1" />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: "Place stock" }));
    // Pick an item in the place form (the picker option, after the table row).
    await user.click(await screen.findByText("Select an item…"));
    const matches = await screen.findAllByText(/Plavac Mali 2021/);
    await user.click(matches[matches.length - 1]);
    await user.type(screen.getByLabelText("Quantity"), "5");
    // Submit (the in-form "Place stock" button).
    const placeButtons = screen.getAllByRole("button", { name: "Place stock" });
    await user.click(placeButtons[placeButtons.length - 1]);

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.items).toEqual([{ inventory_item_id: "itm_1", quantity: 5, unit_type: "cases" }]);
  });

  it("records a sale over the rollup", async () => {
    let body: { items?: { inventory_item_id: string; quantity: number }[] } | null = null;
    server.use(
      http.post(`${API_URL}/customers/:id/consignment/sale`, async ({ request }) => {
        body = (await request.json()) as { items?: { inventory_item_id: string; quantity: number }[] };
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<CustomerConsignmentSection customerId="cus_1" />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: "Record sale" }));
    await user.type(await screen.findByLabelText(/Plavac Mali 2021 Quantity/), "2");
    await user.click(screen.getByRole("button", { name: "Confirm sale" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.items).toEqual([{ inventory_item_id: "itm_1", quantity: 2 }]);
  });
});
