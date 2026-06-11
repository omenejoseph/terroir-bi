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

  it("routes to the dedicated new-supplier page", async () => {
    renderWithProviders(<SuppliersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Add supplier/ }));
    expect(mockRouter.push).toHaveBeenCalledWith("/suppliers/new");
  });

  it("opens the merge dialog from the Merge button", async () => {
    renderWithProviders(<SuppliersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Merge/ }));
    expect(await screen.findByRole("dialog", { name: /Merge suppliers/ })).toBeInTheDocument();
  });

  it("expands a row inline to reveal its actions", async () => {
    renderWithProviders(<SuppliersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Vinogradar d.o.o."));
    // The expanded panel exposes the inline actions (no navigation).
    expect(await screen.findByRole("button", { name: "Deactivate" })).toBeInTheDocument();
    expect(mockRouter.push).not.toHaveBeenCalled();
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
