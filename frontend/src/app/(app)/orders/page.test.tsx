import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import OrdersPage from "./page";
import { API_URL } from "@/lib/config";
import { makeOrder } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("OrdersPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("lists orders", async () => {
    renderWithProviders(<OrdersPage />);
    expect(await screen.findByText("ORD-1001")).toBeInTheDocument();
    expect(screen.getByText("ORD-1002")).toBeInTheDocument();
  });

  it("filters by status tab", async () => {
    let lastStatus: string | null = "unset";
    server.use(
      http.get(`${API_URL}/orders`, ({ request }) => {
        lastStatus = new URL(request.url).searchParams.get("status");
        return HttpResponse.json({
          data: [makeOrder({ order_number: lastStatus === "SHIPPED" ? "ORD-SHIP" : "ORD-1001" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<OrdersPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");
    await user.click(screen.getByRole("tab", { name: "Shipped" }));
    expect(await screen.findByText("ORD-SHIP")).toBeInTheDocument();
    expect(lastStatus).toBe("SHIPPED");
  });

  it("navigates to a new order", async () => {
    renderWithProviders(<OrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /New order/ }));
    expect(mockRouter.push).toHaveBeenCalledWith("/orders/new");
  });

  it("navigates to an order detail on row click", async () => {
    renderWithProviders(<OrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("ORD-1001"));
    expect(mockRouter.push).toHaveBeenCalledWith("/orders/ord_1");
  });

  it("shows a permission error on 403", async () => {
    server.use(
      http.get(`${API_URL}/orders`, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })),
    );
    renderWithProviders(<OrdersPage />);
    expect(await screen.findByText("You don't have permission to view orders.")).toBeInTheDocument();
  });

  it("sends hide_shipped when toggled", async () => {
    let lastHide: string | null = "unset";
    server.use(
      http.get(`${API_URL}/orders`, ({ request }) => {
        lastHide = new URL(request.url).searchParams.get("hide_shipped");
        return HttpResponse.json({
          data: [makeOrder()],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<OrdersPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");
    await user.click(screen.getByLabelText("Hide shipped"));
    await waitFor(() => expect(lastHide).toBe("true"));
  });

  it("paginates to the next page", async () => {
    let lastPage: string | null = null;
    server.use(
      http.get(`${API_URL}/orders`, ({ request }) => {
        lastPage = new URL(request.url).searchParams.get("page");
        return HttpResponse.json({
          data: [makeOrder({ order_number: `PAGE-${lastPage ?? "1"}` })],
          meta: { current_page: Number(lastPage ?? "1"), last_page: 3, per_page: 25, total: 60 },
        });
      }),
    );

    renderWithProviders(<OrdersPage />);
    const user = userEvent.setup();
    await screen.findByText("PAGE-1");
    await user.click(screen.getByRole("button", { name: "Next" }));
    expect(await screen.findByText("PAGE-2")).toBeInTheDocument();
    expect(lastPage).toBe("2");
  });
});
