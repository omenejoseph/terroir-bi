import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import SuppliersPage from "./page";
import { API_URL } from "@/lib/config";
import { makeSession, makeSupplier } from "@/test/fixtures";
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

describe("SuppliersPage (list)", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("lists suppliers from the API", async () => {
    renderWithProviders(<SuppliersPage />);
    expect(await screen.findByText("Vinogradar d.o.o.")).toBeInTheDocument();
    expect(screen.getByText("Staklo Split")).toBeInTheDocument();
  });

  it("filters by status tab (sends is_active=false)", async () => {
    let lastActive: string | null = "unset";
    server.use(
      http.get(`${API_URL}/suppliers`, ({ request }) => {
        lastActive = new URL(request.url).searchParams.get("is_active");
        return HttpResponse.json({
          data: [makeSupplier({ company_name: lastActive === "false" ? "Inactive Vendor" : "Vinogradar d.o.o." })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<SuppliersPage />);
    const user = userEvent.setup();
    await screen.findByText("Vinogradar d.o.o.");

    await user.click(screen.getByRole("tab", { name: "Inactive" }));
    expect(await screen.findByText("Inactive Vendor")).toBeInTheDocument();
    expect(lastActive).toBe("false");
  });

  it("sends the search query", async () => {
    let lastSearch: string | null = null;
    server.use(
      http.get(`${API_URL}/suppliers`, ({ request }) => {
        lastSearch = new URL(request.url).searchParams.get("search");
        return HttpResponse.json({
          data: [makeSupplier()],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<SuppliersPage />);
    const user = userEvent.setup();
    await user.type(await screen.findByPlaceholderText(/Search/), "vino");
    await waitFor(() => expect(lastSearch).toBe("vino"));
  });

  it("creates a supplier from the dialog and captures the POST body", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/suppliers`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeSupplier({ id: "sup_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<SuppliersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Add supplier/ }));

    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Company name"), "New Vendor Ltd");
    await user.click(within(dialog).getByRole("button", { name: "Create supplier" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({ company_name: "New Vendor Ltd" });
    expect(mockRouter.push).toHaveBeenCalledWith("/suppliers/sup_new");
  });

  it("navigates to the detail page on row click", async () => {
    renderWithProviders(<SuppliersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Vinogradar d.o.o."));
    expect(mockRouter.push).toHaveBeenCalledWith("/suppliers/sup_1");
  });

  it("hides the Add button for users without suppliers.manage", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () => HttpResponse.json({ data: makeSession({ roles: ["SALES"] }) })),
      http.get(`${API_URL}/suppliers`, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })),
    );

    renderWithProviders(<SuppliersPage />);
    expect(
      await screen.findByText("You don't have permission to view suppliers."),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Add supplier/ })).not.toBeInTheDocument();
  });
});
