import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import NewSupplierPage from "./page";
import { API_URL } from "@/lib/config";
import { makeSupplier } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("NewSupplierPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("creates an active supplier and routes to its detail page", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/suppliers`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeSupplier({ id: "sup_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewSupplierPage />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText("Company name"), "New Vendor Ltd");
    await user.click(screen.getByRole("button", { name: "Create supplier" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({ company_name: "New Vendor Ltd" });
    // Active by default — the form no longer sends is_active (backend defaults true).
    expect(posted!.is_active).toBeUndefined();
    expect(mockRouter.push).toHaveBeenCalledWith("/suppliers/sup_new");
  });
});
