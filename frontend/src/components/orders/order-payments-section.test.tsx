import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { OrderPaymentsSection } from "./order-payments-section";
import { API_URL } from "@/lib/config";
import { makeInflow, makeOrderPayments, makeSession } from "@/test/fixtures";
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

const money = (minor: number) => ({ minor, currency: "EUR", formatted: `€${(minor / 100).toFixed(2)}` });

describe("OrderPaymentsSection", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the summary and the payment list", async () => {
    renderWithProviders(<OrderPaymentsSection orderId="ord_1" />);
    // amount_paid €500.00 (also the single list row), balance_due €400.00, status PARTIAL.
    expect((await screen.findAllByText("€500.00")).length).toBeGreaterThan(0);
    expect(screen.getByText("€400.00")).toBeInTheDocument();
    expect(screen.getByText("Partial")).toBeInTheDocument();
  });

  it("records a payment and reflects the returned PAID status", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/orders/:id/payments`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          {
            data: makeOrderPayments({
              summary: { amount_paid: money(90000), balance_due: money(0), status: "PAID" },
              payments: [makeInflow(), makeInflow({ id: "inf_2", amount: money(40000) })],
            }),
          },
          { status: 201 },
        );
      }),
    );

    renderWithProviders(<OrderPaymentsSection orderId="ord_1" />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Record payment" }));

    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Amount (minor units)"), "40000");
    await user.click(within(dialog).getByRole("button", { name: "Save payment" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({ amount: 40000 });
    // The PARTIAL badge gives way to PAID once the returned summary seeds the cache.
    await waitFor(() => expect(screen.queryByText("Partial")).not.toBeInTheDocument());
    expect(screen.getByText("€900.00")).toBeInTheDocument();
  });

  it("hides the record button for users without finance.manage", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () => HttpResponse.json({ data: makeSession({ roles: ["SALES"] }) })),
    );

    renderWithProviders(<OrderPaymentsSection orderId="ord_1" />);
    // SALES has finance.view but not finance.manage.
    await screen.findAllByText("€500.00");
    expect(screen.queryByRole("button", { name: "Record payment" })).not.toBeInTheDocument();
  });
});
