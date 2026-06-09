import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import NewCustomerPage from "./page";
import { API_URL } from "@/lib/config";
import { makeCustomer, makePricingTier } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

describe("NewCustomerPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("creates a customer and returns to the list", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/customers`, async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeCustomer({ id: "cus_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewCustomerPage />);
    const user = userEvent.setup();

    await user.type(await screen.findByLabelText("Company name"), "New Winery");
    await user.type(screen.getByLabelText("Email"), "hi@newwinery.com");
    await user.click(screen.getByRole("button", { name: "Create customer" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ company_name: "New Winery", email: "hi@newwinery.com" });
    expect(mockRouter.push).toHaveBeenCalledWith("/customers");
  });

  it("creates a pricing tier inline and assigns it to the new customer", async () => {
    let tierBody: Record<string, unknown> | null = null;
    let customerBody: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/pricing-tiers`, async ({ request }) => {
        tierBody = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makePricingTier({ id: "tier_new", name: "Distributor" }) }, { status: 201 });
      }),
      http.post(`${API_URL}/customers`, async ({ request }) => {
        customerBody = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeCustomer({ id: "cus_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewCustomerPage />);
    const user = userEvent.setup();

    await user.type(await screen.findByLabelText("Company name"), "Tiered Co");
    await user.type(screen.getByLabelText("Email"), "t@co.com");

    // Inline tier creation.
    await user.click(screen.getByRole("button", { name: /New tier/ }));
    await user.type(screen.getByLabelText("Tier name"), "Distributor");
    await user.type(screen.getByLabelText("Tier rebate %"), "15");
    await user.click(screen.getByRole("button", { name: "Add tier" }));

    await waitFor(() => expect(tierBody).not.toBeNull());
    expect(tierBody).toMatchObject({ name: "Distributor", rebate_percent: 15 });

    await user.click(screen.getByRole("button", { name: "Create customer" }));
    await waitFor(() => expect(customerBody).not.toBeNull());
    expect(customerBody).toMatchObject({ pricing_tier_id: "tier_new" });
  });
});