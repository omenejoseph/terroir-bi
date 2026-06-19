import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import OrderDetailPage from "./page";
import { API_URL } from "@/lib/config";
import { makeOrder, makeOrderComment, makeOrderItem, makeSession } from "@/test/fixtures";
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
const money = (minor: number) => ({ minor, currency: "EUR", formatted: `€${(minor / 100).toFixed(2)}` });

describe("OrderDetailPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("leads with the items and shows the detail tabs", async () => {
    renderWithProviders(<OrderDetailPage />);
    expect(await screen.findByText("ORD-1001")).toBeInTheDocument();
    // Items lead the page (no longer behind a tab). They render in two responsive
    // layouts: a table on sm+ and stacked cards on mobile — both are in the DOM.
    const occurrences = screen.getAllByText(/Plavac Mali 2021/);
    expect(occurrences.some((el) => el.closest("tr"))).toBe(true); // desktop table row
    expect(occurrences.some((el) => el.closest("li"))).toBe(true); // mobile stacked card
    // Supporting detail tabs.
    expect(screen.getByRole("tab", { name: "History" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Comments" })).toBeInTheDocument();
  });

  it("shows each line item's bottle size", async () => {
    renderWithProviders(<OrderDetailPage />);
    await screen.findByText("ORD-1001");
    // Rendered in both the table row and the mobile card.
    expect(screen.getAllByText("(750ml)").length).toBeGreaterThan(0);
  });

  it("shows per-line profit when financials are visible", async () => {
    server.use(
      http.get(`${API_URL}/orders/:id`, ({ params }) =>
        HttpResponse.json({
          data: makeOrder({
            id: String(params.id),
            items: [makeOrderItem({ profit: money(3300) })],
          }),
        }),
      ),
    );

    renderWithProviders(<OrderDetailPage />);
    await screen.findByText("ORD-1001");
    // Rendered in both the table row and the mobile card; computed server-side.
    expect(screen.getAllByText("33,00 €").length).toBeGreaterThan(0);
  });

  it("groups items, marks gratis lines, and shows the totals + logistics footer", async () => {
    server.use(
      http.get(`${API_URL}/orders/:id`, ({ params }) =>
        HttpResponse.json({
          data: makeOrder({
            id: String(params.id),
            items: [
              makeOrderItem({ id: "oi_w", name: "Plavac", group: "Wine" }),
              makeOrderItem({
                id: "oi_a",
                name: "Aqua Panna",
                group: "Water",
                unit_price: money(0),
                total: money(0),
                cost_per_unit: null,
                profit: null,
              }),
            ],
          }),
        }),
      ),
    );

    renderWithProviders(<OrderDetailPage />);
    await screen.findByText("ORD-1001");
    expect(screen.getAllByText("Wine").length).toBeGreaterThan(0); // group header
    expect(screen.getAllByText("Water").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Gift").length).toBeGreaterThan(0); // free line (English)
    expect(screen.getAllByText("Total excl. VAT").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Logistics").length).toBeGreaterThan(0);
  });

  it("expands case quantities to the bottle count", async () => {
    server.use(
      http.get(`${API_URL}/orders/:id`, ({ params }) =>
        HttpResponse.json({
          data: makeOrder({
            id: String(params.id),
            items: [makeOrderItem({ unit_type: "cases", quantity: 6, bottles_per_case: 6 })],
          }),
        }),
      ),
    );

    renderWithProviders(<OrderDetailPage />);
    await screen.findByText("ORD-1001");
    // 6 cases × 6 bottles/case = 36 bottles, shown beside the quantity.
    expect(screen.getAllByText("(36 bottles)").length).toBeGreaterThan(0);
  });

  it("shows the item's image when the line has one", async () => {
    server.use(
      http.get(`${API_URL}/orders/:id`, ({ params }) =>
        HttpResponse.json({
          data: makeOrder({
            id: String(params.id),
            items: [makeOrderItem({ image_url: "https://bucket.example/lead.jpg" })],
          }),
        }),
      ),
    );

    renderWithProviders(<OrderDetailPage />);
    await screen.findByText("ORD-1001");
    // Rendered in both the table row and the mobile card; alt = item name.
    const thumbs = await screen.findAllByRole("img", { name: "Plavac Mali 2021" });
    expect(thumbs.length).toBeGreaterThan(0);
    expect(thumbs[0]).toHaveAttribute("src", "https://bucket.example/lead.jpg");
  });

  it("shows the profitability card with revenue, cost and margin", async () => {
    renderWithProviders(<OrderDetailPage />);
    expect(await screen.findByText("Profitability")).toBeInTheDocument();
    expect(screen.getByText("Gross profit")).toBeInTheDocument();
    // 48,00 € shows in the profitability card and now also the items footer/line.
    expect(screen.getAllByText("48,00 €").length).toBeGreaterThan(0); // gross profit
    expect(screen.getByText("53.33%")).toBeInTheDocument(); // margin
  });

  it("warns to set costs when a line has no recorded cost", async () => {
    server.use(
      http.get(`${API_URL}/orders/:id`, ({ params }) =>
        HttpResponse.json({
          data: makeOrder({
            id: String(params.id),
            profitability: {
              revenue: money(9000),
              cogs: money(0),
              logistics: null,
              gross_profit: money(9000),
              margin_percent: "100.00",
              complete: false,
              missing_cost_items: ["Aqua Panna", "San Pellegrino"],
            },
          }),
        }),
      ),
    );

    renderWithProviders(<OrderDetailPage />);
    await screen.findByText("ORD-1001");
    expect(await screen.findByText("Cost data incomplete")).toBeInTheDocument();
    expect(
      screen.getByText("Set costs for Aqua Panna, San Pellegrino to show profitability"),
    ).toBeInTheDocument();
  });

  it("shows the inflows cross-link card", async () => {
    renderWithProviders(<OrderDetailPage />);
    expect(await screen.findByText("Inflows")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /View inflows/ })).toHaveAttribute(
      "href",
      "/inflows?order_id=itm_1",
    );
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

    // Pick the target status from the prominent status control, then apply.
    await user.click(screen.getByRole("button", { name: "Shipped" }));
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
    // The item renders in both layouts; scope to the desktop table row.
    const row = (await screen.findAllByText(/Plavac Mali 2021/))
      .map((el) => el.closest("tr"))
      .find((el): el is HTMLTableRowElement => el !== null)!;

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
    // The item renders in both layouts; scope to the desktop table row.
    const row = (await screen.findAllByText(/Plavac Mali 2021/))
      .map((el) => el.closest("tr"))
      .find((el): el is HTMLTableRowElement => el !== null)!;

    await user.click(within(row).getByText("7,00 €")); // cost cell
    const costInput = within(row).getByLabelText("Cost/unit");
    await user.clear(costInput);
    await user.type(costInput, "8"); // major (€8.00) → 800 minor
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
    // The item renders in both layouts; scope to the desktop table row.
    const row = (await screen.findAllByText(/Plavac Mali 2021/))
      .map((el) => el.closest("tr"))
      .find((el): el is HTMLTableRowElement => el !== null)!;

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
    await user.type(shippingInput, "15"); // major units (€15.00)
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(shipping).not.toBeNull());
    expect(shipping).toMatchObject({ shipping_cost: 1500 }); // sent as minor
  });

  it("shows and edits the order note from the detail page", async () => {
    let body: { notes?: string | null } | null = null;
    server.use(
      http.get(`${API_URL}/orders/:id`, ({ params }) =>
        HttpResponse.json({ data: makeOrder({ id: String(params.id), notes: "Leave at gate" }) }),
      ),
      http.patch(`${API_URL}/orders/:id/notes`, async ({ request }) => {
        body = (await request.json()) as { notes?: string | null };
        return HttpResponse.json({ data: makeOrder({ notes: String(body?.notes ?? "") }) });
      }),
    );

    renderWithProviders(<OrderDetailPage />);
    const user = userEvent.setup();
    await screen.findByText("ORD-1001");
    expect(screen.getByText("Leave at gate")).toBeInTheDocument();

    const noteHeading = screen.getByText("Order note");
    await user.click(within(noteHeading.parentElement!).getByRole("button", { name: "Edit" }));
    const textarea = screen.getByPlaceholderText("Order note…");
    await user.clear(textarea);
    await user.type(textarea, "Call on arrival");
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ notes: "Call on arrival" });
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

    // Type "@" to open the inline mention dropdown, pick a member, then keep typing.
    const box = screen.getByPlaceholderText("Write a comment…");
    await user.type(box, "@Ada");
    await user.click(await screen.findByText("Ada Lovelace")); // option in the @-dropdown
    await user.type(box, "FYI");
    await user.click(screen.getByRole("button", { name: "Comment" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.mentions).toEqual(["usr_1"]);
    expect(body!.content).toContain("@Ada Lovelace");
    expect(body!.content).toContain("FYI");
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
