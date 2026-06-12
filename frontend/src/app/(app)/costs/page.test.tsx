import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import CostsPage from "./page";
import { API_URL } from "@/lib/config";
import { makeCost, makeSession } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
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
    // total_amount fixture is 120,00 € (both default rows share it).
    expect(screen.getAllByText("120,00 €").length).toBeGreaterThan(0);
  });

  it("renders the analytics strip totals", async () => {
    renderWithProviders(<CostsPage />);
    // total_spend 700,00 €, unpaid 500,00 € from makeCostAnalytics.
    expect(await screen.findByText("700,00 €")).toBeInTheDocument();
    expect(screen.getByText("500,00 €")).toBeInTheDocument();
  });

  it("filters by group tab and status dropdown", async () => {
    const params: { group: string | null; status: string | null }[] = [];
    server.use(
      http.get(`${API_URL}/costs`, ({ request }) => {
        const u = new URL(request.url).searchParams;
        params.push({ group: u.get("group"), status: u.get("status") });
        return HttpResponse.json({
          data: [makeCost()],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();

    // Tabs carry the group counts from /costs/group-counts.
    await user.click(await screen.findByRole("tab", { name: /Invoices \(5\)/ }));
    await waitFor(() => expect(params.at(-1)?.group).toBe("invoices"));

    await user.selectOptions(screen.getByLabelText("All Statuses"), "PAID");
    await waitFor(() => expect(params.at(-1)?.status).toBe("PAID"));
  });

  it("filters by category and time period (sends date range)", async () => {
    const params: { category: string | null; from: string | null; to: string | null }[] = [];
    server.use(
      http.get(`${API_URL}/costs`, ({ request }) => {
        const u = new URL(request.url).searchParams;
        params.push({ category: u.get("category"), from: u.get("date_from"), to: u.get("date_to") });
        return HttpResponse.json({
          data: [makeCost()],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }),
    );

    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();
    await screen.findByText("June electricity");

    await user.selectOptions(screen.getByLabelText("All Categories"), "Glass");
    await waitFor(() => expect(params.at(-1)?.category).toBe("Glass"));

    // A preset period sends a date range.
    await user.selectOptions(screen.getByLabelText("Period"), "thisMonth");
    await waitFor(() => {
      expect(params.at(-1)?.from).toBeTruthy();
      expect(params.at(-1)?.to).toBeTruthy();
    });
  });

  it("routes to the dedicated new-cost page", async () => {
    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Add cost/ }));
    expect(mockRouter.push).toHaveBeenCalledWith("/costs/new");
  });

  it("routes to the cost analytics page", async () => {
    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Analytics/ }));
    expect(mockRouter.push).toHaveBeenCalledWith("/costs/analytics");
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
    // Expand the row to reveal its detail panel (status lives there now).
    await user.click(screen.getAllByText("June electricity")[0]);
    await user.selectOptions(await screen.findByLabelText("Set status"), "PAID");

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
    // Expand the row, then delete from its detail panel.
    await user.click(screen.getAllByText("June electricity")[0]);
    await user.click(await screen.findByRole("button", { name: "Delete" }));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(deleted).toBe(true));
  });

  it("edits a cost from its detail panel (PATCH includes fields + supplier)", async () => {
    let patched: { description?: string | null; supplier_id?: string | null } | null = null;
    server.use(
      http.patch(`${API_URL}/costs/:id`, async ({ request, params }) => {
        patched = (await request.json()) as { description?: string | null; supplier_id?: string | null };
        return HttpResponse.json({ data: makeCost({ id: String(params.id) }) });
      }),
    );

    renderWithProviders(<CostsPage />);
    const user = userEvent.setup();
    await screen.findByText("June electricity");
    await user.click(screen.getAllByText("June electricity")[0]); // expand
    await user.click(await screen.findByRole("button", { name: "Edit" }));

    const desc = await screen.findByLabelText("Description");
    await user.clear(desc);
    await user.type(desc, "Updated note");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => {
      expect(patched?.description).toBe("Updated note");
      expect(patched?.supplier_id).toBe("sup_1"); // prefilled supplier sent on update
    });
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
