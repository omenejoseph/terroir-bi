import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import CostsPage from "./page";
import { API_URL } from "@/lib/config";
import { makeCost, makeSession } from "@/test/fixtures";
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

describe("CostsPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("lists costs with formatted amounts", async () => {
    renderWithProviders(<CostsPage />);
    expect(await screen.findByText("June electricity")).toBeInTheDocument();
    // total_amount fixture is €120.00 (both default rows share it).
    expect(screen.getAllByText("€120.00").length).toBeGreaterThan(0);
  });

  it("renders the analytics strip totals", async () => {
    renderWithProviders(<CostsPage />);
    // total_spend €700.00, unpaid €500.00 from makeCostAnalytics.
    expect(await screen.findByText("€700.00")).toBeInTheDocument();
    expect(screen.getByText("€500.00")).toBeInTheDocument();
  });

  it("filters by status tab (sends status param)", async () => {
    let lastStatus: string | null = null;
    server.use(
      http.get(`${API_URL}/costs`, ({ request }) => {
        lastStatus = new URL(request.url).searchParams.get("status");
        return HttpResponse.json({
          data: [makeCost({ status: "PAID" })],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();
    await screen.findByRole("tab", { name: "Paid" });
    await user.click(screen.getByRole("tab", { name: "Paid" }));
    await waitFor(() => expect(lastStatus).toBe("PAID"));
  });

  it("filters by category (sends category param)", async () => {
    let lastCategory: string | null = null;
    server.use(
      http.get(`${API_URL}/costs`, ({ request }) => {
        lastCategory = new URL(request.url).searchParams.get("category");
        return HttpResponse.json({
          data: [makeCost()],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();
    await screen.findByText("June electricity");
    await user.selectOptions(screen.getByLabelText("All categories"), "Glass");
    await waitFor(() => expect(lastCategory).toBe("Glass"));
  });

  it("creates a cost and captures the POST body", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/costs`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeCost({ id: "cost_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Add cost/ }));

    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Category"), "Rent");
    await user.type(within(dialog).getByLabelText("Total (minor units)"), "45000");
    await user.click(within(dialog).getByRole("button", { name: "Save cost" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({ category: "Rent", total_amount: 45000 });
  });

  it("changes a cost status (PATCH body)", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/costs/:id/status`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeCost({ id: String(params.id), status: "PAID" }) });
      }),
    );

    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();
    await screen.findByText("June electricity");
    const selects = screen.getAllByLabelText("Set status");
    await user.selectOptions(selects[0], "PAID");

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ status: "PAID" });
  });

  it("deletes a cost after confirming (ADMIN)", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/costs/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();
    await screen.findByText("June electricity");
    await user.click(screen.getAllByRole("button", { name: "Delete" })[0]);

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(deleted).toBe(true));
  });

  it("hides delete and status controls for a finance-view-only role and shows 403", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () => HttpResponse.json({ data: makeSession({ roles: ["SALES"] }) })),
      http.get(`${API_URL}/costs`, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })),
    );

    renderWithProviders(<CostsPage />);
    expect(await screen.findByText("You don't have permission to view costs.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Add cost/ })).not.toBeInTheDocument();
  });
});
