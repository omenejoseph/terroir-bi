import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import PurchaseOrdersPage from "./page";
import { API_URL } from "@/lib/config";
import { makeSession, makeSupplierOrder } from "@/test/fixtures";
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

describe("PurchaseOrdersPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("lists purchase orders from the API", async () => {
    renderWithProviders(<PurchaseOrdersPage />);
    expect(await screen.findByText("PO-00001")).toBeInTheDocument();
    expect(screen.getByText("PO-00002")).toBeInTheDocument();
  });

  it("filters by status tab (sends status param)", async () => {
    let lastStatus: string | null = null;
    server.use(
      http.get(`${API_URL}/supplier-orders`, ({ request }) => {
        lastStatus = new URL(request.url).searchParams.get("status");
        return HttpResponse.json({
          data: [makeSupplierOrder({ status: "SENT", order_number: "PO-SENT" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<PurchaseOrdersPage />);
    const user = userEvent.setup();
    await screen.findByRole("tab", { name: "Sent" });
    await user.click(screen.getByRole("tab", { name: "Sent" }));
    await waitFor(() => expect(lastStatus).toBe("SENT"));
  });

  it("creates a purchase order and captures numeric line items", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/supplier-orders`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeSupplierOrder({ id: "po_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<PurchaseOrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /New purchase order/ }));

    const dialog = await screen.findByRole("dialog");
    // The supplier select is populated from the suppliers list handler.
    await waitFor(() => expect(within(dialog).getByRole("option", { name: "Vinogradar d.o.o." })).toBeInTheDocument());
    await user.selectOptions(within(dialog).getByLabelText("Supplier"), "sup_1");
    await user.type(within(dialog).getByLabelText(/Description 1/), "Cork 44mm");
    await user.clear(within(dialog).getByLabelText(/Quantity 1/));
    await user.type(within(dialog).getByLabelText(/Quantity 1/), "500");
    await user.type(within(dialog).getByLabelText(/Unit price 1/), "0.25"); // major (€0.25) → 25 minor
    await user.click(within(dialog).getByRole("button", { name: "Create purchase order" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({
      supplier_id: "sup_1",
      items: [{ description: "Cork 44mm", quantity: 500, unit_price: 25 }],
    });
  });

  it("receives a confirmed order after confirming (PATCH status RECEIVED)", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.get(`${API_URL}/supplier-orders`, () =>
        HttpResponse.json({
          data: [makeSupplierOrder({ id: "po_c", order_number: "PO-CONF", status: "CONFIRMED" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        }),
      ),
      http.patch(`${API_URL}/supplier-orders/:id/status`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeSupplierOrder({ id: String(params.id), status: "RECEIVED" }) });
      }),
    );

    renderWithProviders(<PurchaseOrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("PO-CONF"));
    await user.click(await screen.findByRole("button", { name: "Mark received" }));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Mark received" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ status: "RECEIVED" });
  });

  it("does not offer Delete on a SENT order", async () => {
    server.use(
      http.get(`${API_URL}/supplier-orders`, () =>
        HttpResponse.json({
          data: [makeSupplierOrder({ id: "po_s", order_number: "PO-SENT", status: "SENT" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        }),
      ),
    );

    renderWithProviders(<PurchaseOrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("PO-SENT"));
    expect(await screen.findByRole("button", { name: "Mark confirmed" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Delete" })).not.toBeInTheDocument();
  });

  it("hides manage actions and shows the 403 message without permission", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () => HttpResponse.json({ data: makeSession({ roles: ["CELLAR"] }) })),
      http.get(`${API_URL}/supplier-orders`, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })),
    );

    renderWithProviders(<PurchaseOrdersPage />);
    expect(
      await screen.findByText("You don't have permission to view purchase orders."),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /New purchase order/ })).not.toBeInTheDocument();
  });
});
