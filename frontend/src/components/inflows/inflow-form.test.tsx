import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import { InflowForm } from "./inflow-form";
import { API_URL } from "@/lib/config";
import { makeInflow, makeOrder } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

const money = (minor: number) => ({ minor, currency: "EUR", formatted: `€${(minor / 100).toFixed(2)}` });

function noop() {}

describe("InflowForm (edit mode)", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
    server.use(
      http.get(`${API_URL}/orders`, () =>
        HttpResponse.json({
          data: [makeOrder({ id: "ord_1", order_number: "ORD-1001", total_amount: money(24000) })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        }),
      ),
    );
  });

  it("prefills and PATCHes the tied order id", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/inflows/inf_1`, async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeInflow({ id: "inf_1" }) });
      }),
    );

    const inflow = makeInflow({ id: "inf_1", amount: money(50000), order_id: "ord_1", category: "Order payment" });
    renderWithProviders(<InflowForm inflow={inflow} onSaved={noop} onCancel={noop} />);
    const user = userEvent.setup();

    // Amount is prefilled from minor units.
    expect((await screen.findByLabelText("Amount")) as HTMLInputElement).toHaveValue(500);
    // Save (edit) button label.
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ amount: 50000, order_id: "ord_1", category: "Order payment" });
  });
});
