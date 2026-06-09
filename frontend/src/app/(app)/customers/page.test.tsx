import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import CustomersPage from "./page";
import { API_URL } from "@/lib/config";
import { makeCustomer } from "@/test/fixtures";
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

describe("CustomersPage (list)", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("lists customers from the API", async () => {
    renderWithProviders(<CustomersPage />);
    expect(await screen.findByText("Acme Corporation")).toBeInTheDocument();
    expect(screen.getByText("Vinoteka Zagreb")).toBeInTheDocument();
  });

  it("navigates to the create page from the Add button", async () => {
    renderWithProviders(<CustomersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Add customer/ }));
    expect(mockRouter.push).toHaveBeenCalledWith("/customers/new");
  });

  it("expands an inline read-only panel on row click", async () => {
    renderWithProviders(<CustomersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Acme Corporation"));
    // Read-only contact value is now visible, plus the edit affordance.
    expect(await screen.findByText("Jane Doe")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Edit/ })).toBeInTheDocument();
    expect(mockRouter.push).not.toHaveBeenCalled();
  });

  it("switches the panel to the edit form and saves", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/customers/:id`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeCustomer({ id: String(params.id) }) });
      }),
    );

    renderWithProviders(<CustomersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Acme Corporation"));
    await user.click(await screen.findByRole("button", { name: /Edit/ }));

    const company = await screen.findByLabelText("Company name");
    await user.clear(company);
    await user.type(company, "Acme Renamed");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ company_name: "Acme Renamed" });
  });

  it("deactivates a customer after confirming", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/customers/:id`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeCustomer({ id: String(params.id), is_active: false }) });
      }),
    );

    renderWithProviders(<CustomersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Acme Corporation"));
    await user.click(await screen.findByRole("button", { name: "Deactivate" }));

    // Confirm in the dialog.
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Deactivate" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ is_active: false });
  });

  it("filters by status tab (sends is_active)", async () => {
    let lastActive: string | null = "unset";
    server.use(
      http.get(`${API_URL}/customers`, ({ request }) => {
        lastActive = new URL(request.url).searchParams.get("is_active");
        return HttpResponse.json({
          data: [makeCustomer({ company_name: lastActive === "false" ? "Inactive Co" : "Acme Corporation" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<CustomersPage />);
    const user = userEvent.setup();
    await screen.findByText("Acme Corporation");

    await user.click(screen.getByRole("tab", { name: "Inactive" }));
    expect(await screen.findByText("Inactive Co")).toBeInTheDocument();
    expect(lastActive).toBe("false");
  });

  it("shows a permission error on 403", async () => {
    server.use(
      http.get(`${API_URL}/customers`, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })),
    );
    renderWithProviders(<CustomersPage />);
    expect(
      await screen.findByText("You don't have permission to view customers."),
    ).toBeInTheDocument();
  });
});