import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import NewInflowPage from "./page";
import { API_URL } from "@/lib/config";
import { makeInflow } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("NewInflowPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("creates a money-in record (major→minor amount) and routes back", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/inflows`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeInflow({ id: "inf_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewInflowPage />);
    const user = userEvent.setup();

    await user.type(await screen.findByLabelText("Amount"), "450"); // €450 → 45000 minor
    await user.selectOptions(screen.getByLabelText("Status"), "PENDING");
    await user.selectOptions(screen.getByLabelText("Payment Method"), "bank_transfer");
    // Category is the shared creatable combobox (same as costs): open, type, create.
    await user.click(screen.getByLabelText("Category"));
    await user.type(screen.getByPlaceholderText("e.g. Order payment, Grant…"), "Order payment");
    await user.click(screen.getByText('Create "Order payment"'));
    await user.click(screen.getByRole("button", { name: "Save cash inflow" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({
      amount: 45000,
      status: "PENDING",
      payment_method: "bank_transfer",
      category: "Order payment",
      is_credit_note: false,
    });
    await waitFor(() => expect(mockRouter.push).toHaveBeenCalledWith("/inflows"));
  });
});
