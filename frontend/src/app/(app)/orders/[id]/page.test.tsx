import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import OrderDetailPage from "./page";
import { API_URL } from "@/lib/config";
import { makeOrder, makeSession } from "@/test/fixtures";
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
});
