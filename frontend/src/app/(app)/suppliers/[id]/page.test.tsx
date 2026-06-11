import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import SupplierDetailPage from "./page";
import { API_URL } from "@/lib/config";
import { makePriceItem, makeSession, makeSupplier } from "@/test/fixtures";
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

// The useParams mock resolves id => "itm_1"; detail handlers echo it back.
describe("SupplierDetailPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("loads the supplier and saves edits (PATCH body)", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/suppliers/:id`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeSupplier({ id: String(params.id) }) });
      }),
    );

    renderWithProviders(<SupplierDetailPage />);
    const user = userEvent.setup();

    // Read-only view loads; Edit reveals the form (prefilled).
    await screen.findByRole("heading", { name: "Vinogradar d.o.o." });
    await user.click(screen.getByRole("button", { name: "Edit" }));
    const company = await screen.findByLabelText("Company name");
    await waitFor(() => expect((company as HTMLInputElement).value).toBe("Vinogradar d.o.o."));

    await user.clear(company);
    await user.type(company, "Vinogradar Renamed");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ company_name: "Vinogradar Renamed" });
  });

  it("deactivates the supplier via the action (PATCH is_active=false)", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/suppliers/:id`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeSupplier({ id: String(params.id), is_active: false }) });
      }),
    );

    renderWithProviders(<SupplierDetailPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: "Deactivate" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Deactivate" }));

    await waitFor(() => expect(patched).toMatchObject({ is_active: false }));
  });

  it("adds a price-list item with an integer unit_price", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/suppliers/:id/price-items`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makePriceItem({ id: "pli_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<SupplierDetailPage />);
    const user = userEvent.setup();

    // The price list lives under its own tab; the add form is hidden until "Add price".
    await user.click(await screen.findByRole("tab", { name: /Price List/ }));
    await user.click(await screen.findByRole("button", { name: "Add price" }));
    await user.type(screen.getByLabelText("Description"), "Bottle 0.75L");
    await user.type(screen.getByLabelText("Unit price"), "1.8"); // major (€1.80) → 180 minor
    await user.click(screen.getByRole("button", { name: "Save price" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({ description: "Bottle 0.75L", unit_price: 180 });
  });

  it("shows the newly added price item after saving", async () => {
    let created = false;
    server.use(
      http.get(`${API_URL}/suppliers/:id`, ({ params }) =>
        HttpResponse.json({
          data: makeSupplier({
            id: String(params.id),
            price_items: created ? [makePriceItem({ id: "pli_new", description: "Fresh Cork" })] : [],
          }),
        }),
      ),
      http.post(`${API_URL}/suppliers/:id/price-items`, () => {
        created = true; // next refetch returns the supplier with the new item
        return HttpResponse.json({ data: makePriceItem({ id: "pli_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<SupplierDetailPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("tab", { name: /Price List/ }));
    await user.click(await screen.findByRole("button", { name: "Add price" }));
    await user.type(screen.getByLabelText("Description"), "Fresh Cork");
    await user.type(screen.getByLabelText("Unit price"), "2");
    await user.click(screen.getByRole("button", { name: "Save price" }));

    // The list refetches and the new item appears (and the form closes again).
    expect(await screen.findByText("Fresh Cork")).toBeInTheDocument();
    expect(screen.queryByLabelText("Description")).not.toBeInTheDocument();
  });

  it("edits a price-list item (PATCH by id)", async () => {
    let patched: Record<string, unknown> | null = null;
    let url = "";
    server.use(
      http.patch(`${API_URL}/suppliers/:id/price-items/:priceItem`, async ({ request }) => {
        url = request.url;
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makePriceItem({ id: "pli_1", unit_price: { minor: 4000, currency: "EUR", formatted: "€40.00" } }) });
      }),
    );

    renderWithProviders(<SupplierDetailPage />);
    const user = userEvent.setup();

    // The default detail handler returns "Natural cork 44mm" (pli_1).
    await user.click(await screen.findByRole("tab", { name: /Price List/ }));
    await screen.findByText("Natural cork 44mm");
    await user.click(screen.getByRole("button", { name: /Edit “Natural cork 44mm”/ }));

    // Form is prefilled; change the price and save.
    const price = screen.getByLabelText("Unit price");
    await user.clear(price);
    await user.type(price, "40");
    await user.click(screen.getByRole("button", { name: "Save price" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(url).toContain("/price-items/pli_1");
    expect(patched).toMatchObject({ description: "Natural cork 44mm", unit_price: 4000 });
  });

  it("shows the summary stat cards", async () => {
    renderWithProviders(<SupplierDetailPage />);
    expect(await screen.findByText("450,00 €")).toBeInTheDocument(); // total_costs 45000 (waits for stats)
    expect(screen.getByText("Total Costs")).toBeInTheDocument();
    expect(screen.getByText("Cost Entries")).toBeInTheDocument();
    expect(screen.getByText("Price Items")).toBeInTheDocument();
  });

  it("shows the cost-change history under the History tab", async () => {
    renderWithProviders(<SupplierDetailPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("tab", { name: /History/ }));
    expect(await screen.findByText("Cost change history")).toBeInTheDocument();
    // The change row shows old → new price.
    expect(screen.getByText("25,00 €")).toBeInTheDocument(); // new price (2500)
    expect(screen.getByText("20,00 €")).toBeInTheDocument(); // old price (2000)
  });

  it("enables the supplier portal and reveals the link", async () => {
    renderWithProviders(<SupplierDetailPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: "Enable Portal" }));
    expect(await screen.findByRole("button", { name: "Open Portal" })).toBeInTheDocument();
    expect(screen.getByDisplayValue(/\/supplier-portal\/tok_abc123$/)).toBeInTheDocument();
  });

  it("deletes a price-list item after confirming", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/suppliers/:id/price-items/:priceItem`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<SupplierDetailPage />);
    const user = userEvent.setup();

    // The default detail handler returns one price item.
    await user.click(await screen.findByRole("tab", { name: /Price List/ }));
    await screen.findByText("Natural cork 44mm");
    const list = screen.getByText("Natural cork 44mm").closest("li")!;
    await user.click(within(list).getByRole("button", { name: "Remove price" }));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(deleted).toBe(true));
  });

  it("hides the delete-supplier button for users without suppliers.delete", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () => HttpResponse.json({ data: makeSession({ roles: ["MANAGER"] }) })),
    );

    renderWithProviders(<SupplierDetailPage />);
    await screen.findByRole("heading", { name: "Vinogradar d.o.o." });
    // MANAGER can manage suppliers but cannot delete them (no wildcard).
    expect(screen.queryByRole("button", { name: "Delete" })).not.toBeInTheDocument();
  });

  it("deletes the supplier and redirects to the list", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/suppliers/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<SupplierDetailPage />);
    const user = userEvent.setup();
    await screen.findByRole("heading", { name: "Vinogradar d.o.o." });

    await user.click(screen.getByRole("button", { name: "Delete" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(deleted).toBe(true));
    expect(mockRouter.push).toHaveBeenCalledWith("/suppliers");
  });
});
