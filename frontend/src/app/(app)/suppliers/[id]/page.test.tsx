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
    const company = await screen.findByLabelText("Company name");
    await waitFor(() => expect((company as HTMLInputElement).value).toBe("Vinogradar d.o.o."));

    const user = userEvent.setup();
    await user.clear(company);
    await user.type(company, "Vinogradar Renamed");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ company_name: "Vinogradar Renamed" });
    expect(mockRouter.push).toHaveBeenCalledWith("/suppliers");
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

    await user.type(await screen.findByLabelText("Description"), "Bottle 0.75L");
    await user.type(screen.getByLabelText("Unit price (minor units)"), "180");
    await user.click(screen.getByRole("button", { name: "Add price" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({ description: "Bottle 0.75L", unit_price: 180 });
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
    await screen.findByLabelText("Company name");
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
    await screen.findByLabelText("Company name");

    await user.click(screen.getByRole("button", { name: "Delete" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(deleted).toBe(true));
    expect(mockRouter.push).toHaveBeenCalledWith("/suppliers");
  });
});
