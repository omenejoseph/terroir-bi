import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import OrderDetailPage from "./page";
import { API_URL } from "@/lib/config";
import { makeOrder, makeOrderComment, makeSession } from "@/test/fixtures";
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

// useParams is mocked in setup to return { id: "itm_1" }; orders use the same id slot.

describe("OrderDetailPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the order with tabs", async () => {
    renderWithProviders(<OrderDetailPage />);
    expect(await screen.findByText("ORD-1001")).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Items" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Comments" })).toBeInTheDocument();
    // Default catalog item line.
    expect(screen.getByText(/Plavac Mali 2021/)).toBeInTheDocument();
  });

  it("changes the status after confirming", async () => {
    let patched: { status?: string } | null = null;
    server.use(
      http.patch(`${API_URL}/orders/:id/status`, async ({ request }) => {
        patched = (await request.json()) as { status?: string };
        return HttpResponse.json({ data: makeOrder({ status: "SHIPPED" }) });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");

    await user.selectOptions(screen.getByLabelText("Change status"), "SHIPPED");
    await user.click(screen.getByRole("button", { name: "Update status" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Confirm" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ status: "SHIPPED" });
  });

  it("adds an item to the order", async () => {
    let posted: { items?: unknown[] } | null = null;
    server.use(
      http.post(`${API_URL}/orders/:id/items`, async ({ request }) => {
        posted = (await request.json()) as { items?: unknown[] };
        return HttpResponse.json({ data: makeOrder() });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");

    await user.click(screen.getByRole("button", { name: "Add items" }));
    await user.click(await screen.findByText("Select an item…"));
    // The picker dropdown option renders after the existing item row in the DOM.
    const matches = await screen.findAllByText(/Plavac Mali 2021/);
    await user.click(matches[matches.length - 1]);
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted!.items!.length).toBe(1);
  });

  it("hides cost for users without financials.view", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () =>
        HttpResponse.json({ data: makeSession({ roles: ["CELLAR"] }) }),
      ),
    );

    renderWithProviders(<OrderDetailPage />);
    await screen.findByText("ORD-1001");
    expect(screen.queryByText("Cost/unit")).not.toBeInTheDocument();
  });

  it("posts a comment", async () => {
    let commented: { content?: string } | null = null;
    server.use(
      http.post(`${API_URL}/orders/:id/comments`, async ({ request }) => {
        commented = (await request.json()) as { content?: string };
        return HttpResponse.json({ data: { id: "c2", content: "Hi", author: null, created_at: null } }, { status: 201 });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");

    await user.click(screen.getByRole("tab", { name: "Comments" }));
    await user.type(await screen.findByPlaceholderText("Write a comment…"), "Looks good");
    await user.click(screen.getByRole("button", { name: "Comment" }));

    await waitFor(() => expect(commented).not.toBeNull());
    expect(commented).toMatchObject({ content: "Looks good" });
  });

  it("edits an item's quantity (unit is locked to the catalog sales unit)", async () => {
    let patched: { quantity?: number; unit_type?: string } | null = null;
    server.use(
      http.patch(`${API_URL}/order-items/:id`, async ({ request }) => {
        patched = (await request.json()) as { quantity?: number; unit_type?: string };
        return HttpResponse.json({ data: makeOrder() });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    const row = (await screen.findByText(/Plavac Mali 2021/)).closest("tr")!;

    await user.click(within(row).getByRole("button", { name: "Edit" }));
    // The unit Select is disabled for a catalog line.
    expect(within(row).getByLabelText("Unit")).toBeDisabled();
    const qty = within(row).getByLabelText("Qty");
    await user.clear(qty);
    await user.type(qty, "12");
    await user.click(within(row).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ quantity: 12, unit_type: "bottles" });
  });

  it("edits an item's cost", async () => {
    let patched: { cost_per_unit?: number } | null = null;
    server.use(
      http.patch(`${API_URL}/order-items/:id/cost`, async ({ request }) => {
        patched = (await request.json()) as { cost_per_unit?: number };
        return HttpResponse.json({ data: makeOrder() });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    const row = (await screen.findByText(/Plavac Mali 2021/)).closest("tr")!;

    await user.click(within(row).getByText("7,00 €")); // cost cell
    const costInput = within(row).getByLabelText("Cost/unit");
    await user.clear(costInput);
    await user.type(costInput, "800");
    await user.click(within(row).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ cost_per_unit: 800 });
  });

  it("deletes an item after confirming", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/order-items/:id`, () => {
        deleted = true;
        return HttpResponse.json({ data: makeOrder({ items: [] }) });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    const row = (await screen.findByText(/Plavac Mali 2021/)).closest("tr")!;

    await user.click(within(row).getByRole("button", { name: "Remove" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Remove" }));

    await waitFor(() => expect(deleted).toBe(true));
  });

  it("edits shipping/notes/backorder from the details card", async () => {
    let shipping: { shipping_cost?: number | null } | null = null;
    server.use(
      http.patch(`${API_URL}/orders/:id/shipping`, async ({ request }) => {
        shipping = (await request.json()) as { shipping_cost?: number | null };
        return HttpResponse.json({ data: makeOrder() });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");

    const detailsHeading = screen.getByText("Order details");
    await user.click(within(detailsHeading.parentElement!).getByRole("button", { name: "Edit" }));
    const shippingInput = screen.getByLabelText("Shipping");
    await user.clear(shippingInput);
    await user.type(shippingInput, "1500");
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(shipping).not.toBeNull());
    expect(shipping).toMatchObject({ shipping_cost: 1500 });
  });

  it("adds a mention to a comment", async () => {
    let body: { content?: string; mentions?: string[] } | null = null;
    server.use(
      http.post(`${API_URL}/orders/:id/comments`, async ({ request }) => {
        body = (await request.json()) as { content?: string; mentions?: string[] };
        return HttpResponse.json({ data: { id: "c3", content: "x", author: null, created_at: null } }, { status: 201 });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");
    await user.click(screen.getByRole("tab", { name: "Comments" }));

    await user.click(await screen.findByRole("button", { name: "Mention" }));
    await user.click(await screen.findByText("Ada Lovelace")); // member from the dropdown
    await user.type(screen.getByPlaceholderText("Write a comment…"), "FYI");
    await user.click(screen.getByRole("button", { name: "Comment" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ content: "FYI", mentions: ["usr_1"] });
  });

  it("edits and deletes a comment (author/admin)", async () => {
    let edited: { content?: string } | null = null;
    let deleted = false;
    server.use(
      http.get(`${API_URL}/orders/:id`, ({ params }) =>
        HttpResponse.json({ data: makeOrder({ id: String(params.id), comments: [makeOrderComment()] }) }),
      ),
      http.patch(`${API_URL}/order-comments/:id`, async ({ request }) => {
        edited = (await request.json()) as { content?: string };
        return HttpResponse.json({ data: makeOrderComment() });
      }),
      http.delete(`${API_URL}/order-comments/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");
    await user.click(screen.getByRole("tab", { name: "Comments" }));

    const li = (await screen.findByText("Packed and ready.")).closest("li")!;
    await user.click(within(li).getByText("Edit"));
    const input = within(li).getByDisplayValue("Packed and ready.");
    await user.clear(input);
    await user.type(input, "Edited");
    await user.click(within(li).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(edited).toMatchObject({ content: "Edited" }));

    await user.click(within(li).getByText("Delete"));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(deleted).toBe(true));
  });

  it("shows a Payments tab with the summary for finance users", async () => {
    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");

    await user.click(screen.getByRole("tab", { name: "Payments" }));
    // Default order-payments handler: paid 500,00 €, balance 400,00 €, PARTIAL.
    expect(await screen.findByText("Balance due")).toBeInTheDocument();
    expect(screen.getByText("400,00 €")).toBeInTheDocument();
    expect(screen.getByText("Partial")).toBeInTheDocument();
  });

  it("renders the payments 403 state when the endpoint is forbidden", async () => {
    server.use(
      http.get(`${API_URL}/orders/:id/payments`, () =>
        HttpResponse.json({ message: "Forbidden." }, { status: 403 }),
      ),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");

    await user.click(screen.getByRole("tab", { name: "Payments" }));
    expect(
      await screen.findByText("You don't have permission to view payments."),
    ).toBeInTheDocument();
  });
});
