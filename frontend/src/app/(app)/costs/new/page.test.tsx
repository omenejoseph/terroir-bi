import { http, HttpResponse } from "msw";
import { describe, expect, it, beforeEach } from "vitest";

import NewCostPage from "./page";
import { API_URL } from "@/lib/config";
import { makeCost } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("NewCostPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("creates a cost with status + payment method and routes back", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/costs`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeCost({ id: "cost_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<NewCostPage />);
    const user = userEvent.setup();

    // Category is a creatable combobox: open it, type, then commit the "Create" row.
    await user.click(await screen.findByLabelText("Category"));
    await user.type(screen.getByPlaceholderText("Type or select a category…"), "Rent");
    await user.click(screen.getByText('Create "Rent"'));

    await user.type(screen.getByLabelText("Total Amount"), "450"); // major (€450) → 45000 minor
    await user.selectOptions(screen.getByLabelText("Status"), "APPROVED");
    await user.selectOptions(screen.getByLabelText("Payment Method"), "bank_transfer");
    await user.click(screen.getByRole("button", { name: "Save cost" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({
      category: "Rent",
      total_amount: 45000,
      status: "APPROVED",
      payment_method: "bank_transfer",
    });
    await waitFor(() => expect(mockRouter.push).toHaveBeenCalledWith("/costs"));
  });
});
