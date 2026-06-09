import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import PublicOrderPage from "./page";
import { API_URL } from "@/lib/config";
import { makePublicCatalog } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

// useParams is mocked in setup to return { token: "tok_1", ... }.

describe("PublicOrderPage", () => {
  beforeEach(() => seedLocale("en"));

  it("renders the customer's catalog with prices", async () => {
    renderWithProviders(<PublicOrderPage />);
    expect(await screen.findByText("Acme Corporation")).toBeInTheDocument();
    expect(screen.getByText("Plavac Mali 2021")).toBeInTheDocument();
    expect(screen.getByText(/€150\.00/)).toBeInTheDocument(); // the product's price
  });

  it("submits an order and shows the confirmation", async () => {
    let body: { items?: { inventory_item_id: string; quantity: number }[] } | null = null;
    server.use(
      http.post(`${API_URL}/public/:token/orders`, async ({ request }) => {
        body = (await request.json()) as { items?: { inventory_item_id: string; quantity: number }[] };
        return HttpResponse.json({ data: { order_number: "ORD-2001" } }, { status: 201 });
      }),
    );

    renderWithProviders(<PublicOrderPage />);
    const user = userEvent.setup();

    await user.type(await screen.findByLabelText("Quantity for Plavac Mali 2021"), "3");
    await user.click(screen.getByRole("button", { name: "Submit order" }));

    expect(await screen.findByText(/ORD-2001/)).toBeInTheDocument();
    expect(body!.items).toEqual([{ inventory_item_id: "itm_1", quantity: 3, unit_type: "cases" }]);
  });

  it("surfaces a rate-limit (429) gracefully", async () => {
    server.use(
      http.post(`${API_URL}/public/:token/orders`, () =>
        HttpResponse.json({ message: "Too Many Attempts." }, { status: 429 }),
      ),
    );

    renderWithProviders(<PublicOrderPage />);
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText("Quantity for Plavac Mali 2021"), "2");
    await user.click(screen.getByRole("button", { name: "Submit order" }));

    expect(await screen.findByText(/Too many attempts/i)).toBeInTheDocument();
  });

  it("hides prices when the customer has hide_prices", async () => {
    server.use(
      http.get(`${API_URL}/public/:token/catalog`, () =>
        HttpResponse.json({
          data: makePublicCatalog({
            customer: { company_name: "Acme Corporation", hide_prices: true, allow_single_bottle: false },
          }),
        }),
      ),
    );

    renderWithProviders(<PublicOrderPage />);
    expect(await screen.findByText("Plavac Mali 2021")).toBeInTheDocument();
    expect(screen.queryByText(/€150\.00/)).not.toBeInTheDocument();
  });

  it("shows an error for an invalid link", async () => {
    server.use(
      http.get(`${API_URL}/public/:token/catalog`, () =>
        HttpResponse.json({ message: "Not found" }, { status: 404 }),
      ),
    );

    renderWithProviders(<PublicOrderPage />);
    expect(await screen.findByText(/invalid or has expired/i)).toBeInTheDocument();
  });
});
