import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import InflowsPage from "./page";
import { API_URL } from "@/lib/config";
import { makeInflow, makeSession } from "@/test/fixtures";
import { mockRouter, mockSearchParams } from "@/test/setup";
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

describe("InflowsPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("lists money-in records with formatted amounts", async () => {
    renderWithProviders(<InflowsPage />);
    // makeInflow category "Order payment"; amount money(50000) = 500,00 € (hr-HR for EUR).
    expect(await screen.findByText("Order payment")).toBeInTheDocument();
    expect(screen.getAllByText("500,00 €").length).toBeGreaterThan(0);
  });

  it("links each inflow to its tied order from the expanded card", async () => {
    renderWithProviders(<InflowsPage />);
    const user = userEvent.setup();
    const cards = await screen.findAllByRole("button", { expanded: false });
    await user.click(cards[0]);
    const link = await screen.findByRole("link", { name: /View order/ });
    expect(link).toHaveAttribute("href", "/orders/ord_1");
  });

  it("pre-filters by order_id from the URL and sends it", async () => {
    mockSearchParams.set("order_id", "ord_1");
    let lastOrder: string | null = null;
    server.use(
      http.get(`${API_URL}/inflows`, ({ request }) => {
        lastOrder = new URL(request.url).searchParams.get("order_id");
        return HttpResponse.json({
          data: [makeInflow()],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<InflowsPage />);
    expect(await screen.findByText(/Showing cash inflows for order/)).toBeInTheDocument();
    await waitFor(() => expect(lastOrder).toBe("ord_1"));
  });

  it("filters by status tab (sends status param)", async () => {
    let lastStatus: string | null = "unset";
    server.use(
      http.get(`${API_URL}/inflows`, ({ request }) => {
        lastStatus = new URL(request.url).searchParams.get("status");
        return HttpResponse.json({
          data: [makeInflow({ status: "PENDING", category: "Expected grant" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<InflowsPage />);
    const user = userEvent.setup();
    await screen.findByRole("tab", { name: "Pending" });
    await user.click(screen.getByRole("tab", { name: "Pending" }));
    await waitFor(() => expect(lastStatus).toBe("PENDING"));
  });

  it("filters by customer (sends customer_id param)", async () => {
    let lastCustomer: string | null = null;
    server.use(
      http.get(`${API_URL}/inflows`, ({ request }) => {
        lastCustomer = new URL(request.url).searchParams.get("customer_id");
        return HttpResponse.json({
          data: [makeInflow()],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<InflowsPage />);
    const user = userEvent.setup();
    await screen.findByText("Order payment");
    // The customer dropdown is populated from the customers list handler (cus_1).
    await user.selectOptions(screen.getByLabelText("All customers"), "cus_1");
    await waitFor(() => expect(lastCustomer).toBe("cus_1"));
  });

  it("routes to the inflow analytics page", async () => {
    renderWithProviders(<InflowsPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Analytics/ }));
    expect(mockRouter.push).toHaveBeenCalledWith("/inflows/analytics");
  });

  it("routes to the dedicated new-money-in page", async () => {
    renderWithProviders(<InflowsPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Add cash inflow/ }));
    expect(mockRouter.push).toHaveBeenCalledWith("/inflows/new");
  });

  it("changes an inflow status (PATCH body)", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/inflows/:id/status`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeInflow({ id: String(params.id), status: "PENDING" }) });
      }),
    );

    renderWithProviders(<InflowsPage />);
    const user = userEvent.setup();
    const cards = await screen.findAllByRole("button", { expanded: false });
    await user.click(cards[0]);
    await user.selectOptions(await screen.findByLabelText("Set status"), "PENDING");

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ status: "PENDING" });
  });

  it("deletes an inflow after confirming (ADMIN)", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/inflows/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<InflowsPage />);
    const user = userEvent.setup();
    const cards = await screen.findAllByRole("button", { expanded: false });
    await user.click(cards[0]);
    await user.click(await screen.findByRole("button", { name: "Delete" }));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(deleted).toBe(true));
  });

  it("hides manage controls for a finance-view-only role and shows 403", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () => HttpResponse.json({ data: makeSession({ roles: ["SALES"] }) })),
      http.get(`${API_URL}/inflows`, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })),
    );

    renderWithProviders(<InflowsPage />);
    expect(await screen.findByText("You don't have permission to view cash inflow.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Add cash inflow/ })).not.toBeInTheDocument();
  });
});
