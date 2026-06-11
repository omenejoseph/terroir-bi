import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { PricingTab } from "./pricing-tab";
import { API_URL } from "@/lib/config";
import {
  makeItem,
  makeItemCustomerPrice,
  makeItemTierPrice,
  makePricingTier,
} from "@/test/fixtures";
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

// Default price €29.99 so the tier price €19.99 shows a clean −33.3%.
const item = makeItem({ default_price: { minor: 2999, currency: "EUR", formatted: "€29.99" } });

describe("PricingTab", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("shows the default price and tier pricing with the discount", async () => {
    server.use(
      http.get(`${API_URL}/inventory-items/:id/tier-prices`, () =>
        HttpResponse.json({ data: [makeItemTierPrice()] }),
      ),
    );

    renderWithProviders(<PricingTab item={item} canManage />);

    expect(await screen.findByText("Default Price")).toBeInTheDocument();
    expect(await screen.findByText("Wholesale")).toBeInTheDocument();
    expect(screen.getByText("19,99 €")).toBeInTheDocument();
    expect(screen.getByText("-33.3%")).toBeInTheDocument(); // (1999 − 2999) / 2999
  });

  it("adds a tier price", async () => {
    let body: Record<string, unknown> | null = null;
    let tierInUrl: string | null = null;
    server.use(
      http.put(`${API_URL}/inventory-items/:item/tier-price/:tier`, async ({ request, params }) => {
        tierInUrl = String(params.tier);
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: { minor: 1999, currency: "EUR" } });
      }),
    );

    renderWithProviders(<PricingTab item={item} canManage />);
    const user = userEvent.setup();

    await screen.findByText("Tier Pricing");
    const tierForm = screen.getByRole("button", { name: "Add Tier" }).closest("form") as HTMLElement;
    await user.selectOptions(within(tierForm).getByLabelText("Select tier"), "tier_1");

    // Selecting a tier prefills the price from the default (€29.99); override it.
    const priceInput = within(tierForm).getByLabelText("Price");
    expect(priceInput).toHaveValue(29.99);
    await user.clear(priceInput);
    await user.type(priceInput, "19.99");
    // The live discount reflects the typed price vs. the default.
    expect(within(tierForm).getByText("-33.3%")).toBeInTheDocument();
    await user.click(within(tierForm).getByRole("button", { name: "Add Tier" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(tierInUrl).toBe("tier_1");
    expect(body).toMatchObject({ price: 1999 }); // major → minor
  });

  it("removes a tier price after confirming", async () => {
    let deletedTier: string | null = null;
    server.use(
      http.get(`${API_URL}/inventory-items/:id/tier-prices`, () =>
        HttpResponse.json({ data: [makeItemTierPrice()] }),
      ),
      http.delete(`${API_URL}/inventory-items/:item/tier-price/:tier`, ({ params }) => {
        deletedTier = String(params.tier);
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<PricingTab item={item} canManage />);
    const user = userEvent.setup();

    const row = (await screen.findByText("Wholesale")).closest("li") as HTMLElement;
    await user.click(within(row).getByRole("button", { name: "Remove" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Remove" }));

    await waitFor(() => expect(deletedTier).toBe("tier_1"));
  });

  it("creates a new tier inline", async () => {
    let created: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/pricing-tiers`, async ({ request }) => {
        created = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          { data: makePricingTier({ id: "tier_new", name: "Export" }) },
          { status: 201 },
        );
      }),
    );

    renderWithProviders(<PricingTab item={item} canManage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: /New tier/ }));
    await user.type(screen.getByLabelText("Tier name"), "Export");
    await user.type(screen.getByLabelText("Rebate %"), "25");
    await user.click(screen.getByRole("button", { name: "Create" }));

    await waitFor(() => expect(created).not.toBeNull());
    expect(created).toMatchObject({ name: "Export", rebate_percent: 25 });
  });

  it("shows the customer-specific empty state", async () => {
    renderWithProviders(<PricingTab item={item} canManage />);
    expect(await screen.findByText("No customer-specific pricing set.")).toBeInTheDocument();
  });

  it("adds a customer-specific price", async () => {
    let body: Record<string, unknown> | null = null;
    let customerInUrl: string | null = null;
    server.use(
      http.put(
        `${API_URL}/inventory-items/:item/customer-price/:customer`,
        async ({ request, params }) => {
          customerInUrl = String(params.customer);
          body = (await request.json()) as Record<string, unknown>;
          return HttpResponse.json({ data: { minor: 1999, currency: "EUR" } });
        },
      ),
    );

    renderWithProviders(<PricingTab item={item} canManage />);
    const user = userEvent.setup();

    await screen.findByText("Customer-Specific Pricing");
    await user.click(screen.getByLabelText("Customer")); // opens the picker
    await user.type(screen.getByPlaceholderText("Search customers…"), "Acme");
    await user.click(await screen.findByText("Acme Corporation"));

    // The "Price" label exists in both add forms; scope to the customer form.
    const form = screen.getByRole("button", { name: "Set price" }).closest("form") as HTMLElement;
    await user.clear(within(form).getByLabelText("Price"));
    await user.type(within(form).getByLabelText("Price"), "19.99");
    await user.click(screen.getByRole("button", { name: "Set price" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(customerInUrl).toBe("cus_1");
    expect(body).toMatchObject({ price: 1999 });
  });

  it("lists customer-specific prices", async () => {
    server.use(
      http.get(`${API_URL}/inventory-items/:id/customer-prices`, () =>
        HttpResponse.json({ data: [makeItemCustomerPrice()] }),
      ),
    );

    renderWithProviders(<PricingTab item={item} canManage />);

    expect(await screen.findByText("Konoba Riva")).toBeInTheDocument();
    expect(screen.getByText("15,00 €")).toBeInTheDocument();
  });
});
