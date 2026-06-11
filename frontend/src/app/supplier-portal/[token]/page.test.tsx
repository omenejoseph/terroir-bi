import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach, vi } from "vitest";

import SupplierPortalPage from "./page";
import { API_URL } from "@/lib/config";
import { makeSupplierOrder, makeSupplierPortal } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedLocale, userEvent, waitFor, within } from "@/test/utils";

vi.mock("next/navigation", () => ({ useParams: () => ({ token: "tok_abc123" }) }));

describe("SupplierPortalPage (public)", () => {
  beforeEach(() => seedLocale("en"));

  it("shows the supplier, open orders, and lets the supplier confirm a sent order", async () => {
    let confirmedId: string | null = null;
    server.use(
      http.patch(`${API_URL}/public/supplier/:token/orders/:order/confirm`, ({ params }) => {
        confirmedId = String(params.order);
        return HttpResponse.json({ data: makeSupplierOrder({ id: String(params.order), status: "CONFIRMED" }) });
      }),
    );

    renderWithProviders(<SupplierPortalPage />);
    const user = userEvent.setup();

    expect(await screen.findByText("Serrano and Crawford Inc")).toBeInTheDocument();
    expect(screen.getByText("Hiram Richards")).toBeInTheDocument();
    // Orders tab is default; the SENT order shows a Confirm button.
    expect(screen.getByText("PO-1")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Confirm order" }));
    await waitFor(() => expect(confirmedId).toBe("po_1"));
  });

  it("uploads a CSV and shows the added/updated result", async () => {
    let body: { items: { description: string; unit_price: number; unit: string | null }[] } | null = null;
    server.use(
      http.post(`${API_URL}/public/supplier/:token/price-items/import`, async ({ request }) => {
        body = (await request.json()) as typeof body;
        return HttpResponse.json({ data: { added: 2, updated: 1, total: 3 } });
      }),
    );

    renderWithProviders(<SupplierPortalPage />);
    const user = userEvent.setup();

    await screen.findByText("Serrano and Crawford Inc");
    await user.click(screen.getByRole("tab", { name: /Price List/ }));

    const csv = "description,price,unit\nCork,2.50,units\nCapsule,0.80,\nLabel,0.40,units";
    const file = new File([csv], "prices.csv", { type: "text/csv" });
    await user.upload(screen.getByLabelText("Upload CSV"), file);

    await waitFor(() => expect(body).not.toBeNull());
    // Prices parsed major → minor; header row skipped.
    expect(body!.items).toEqual([
      { description: "Cork", unit_price: 250, unit: "units" },
      { description: "Capsule", unit_price: 80, unit: null },
      { description: "Label", unit_price: 40, unit: "units" },
    ]);
    expect(await screen.findByText("2 added · 1 updated.")).toBeInTheDocument();
  });

  it("shows an invalid-link message for a bad token", async () => {
    server.use(
      http.get(`${API_URL}/public/supplier/:token`, () => new HttpResponse(null, { status: 404 })),
    );
    renderWithProviders(<SupplierPortalPage />);
    expect(await screen.findByText(/invalid or has been disabled/)).toBeInTheDocument();
  });

  it("shows an empty-orders state when there are none", async () => {
    server.use(
      http.get(`${API_URL}/public/supplier/:token`, () =>
        HttpResponse.json({ data: makeSupplierPortal({ orders: [] }) }),
      ),
    );
    renderWithProviders(<SupplierPortalPage />);
    expect(await screen.findByText("No open orders right now.")).toBeInTheDocument();
  });
});
