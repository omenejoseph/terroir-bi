import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { OrderConsignmentSection } from "./order-consignment-section";
import { API_URL } from "@/lib/config";
import { makeConsignmentSummary, makeOrder } from "@/test/fixtures";
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

const order = makeOrder({ is_consignment: true });

describe("OrderConsignmentSection", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the consignment summary", async () => {
    renderWithProviders(<OrderConsignmentSection order={order} canManage />);
    expect(await screen.findByText("Open")).toBeInTheDocument();
    expect(screen.getByText("Totals")).toBeInTheDocument();
  });

  it("records a sale", async () => {
    let body: { items?: { order_item_id: string; quantity: number }[] } | null = null;
    server.use(
      http.post(`${API_URL}/orders/:id/consignment/sale`, async ({ request }) => {
        body = (await request.json()) as { items?: { order_item_id: string; quantity: number }[] };
        return HttpResponse.json({ data: makeConsignmentSummary() });
      }),
    );

    renderWithProviders(<OrderConsignmentSection order={order} canManage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Record sale" }));

    await user.type(await screen.findByLabelText(/Plavac Mali 2021 Quantity/), "3");
    await user.click(screen.getByRole("button", { name: "Confirm sale" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.items).toEqual([{ order_item_id: "oi_1", quantity: 3 }]);
  });

  it("closes the consignment after confirming", async () => {
    let closed = false;
    server.use(
      http.post(`${API_URL}/orders/:id/consignment/close`, () => {
        closed = true;
        return HttpResponse.json({ data: makeConsignmentSummary({ closed_at: "2026-06-03T00:00:00+00:00" }) });
      }),
    );

    renderWithProviders(<OrderConsignmentSection order={order} canManage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Close consignment" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Confirm" }));

    await waitFor(() => expect(closed).toBe(true));
  });
});
