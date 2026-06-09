import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import WorkOrdersPage from "./page";
import { API_URL } from "@/lib/config";
import { makeWorkOrder } from "@/test/fixtures";
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

const isoDay = (d: Date) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const addDays = (d: Date, n: number) => {
  const x = new Date(d);
  x.setDate(x.getDate() + n);
  return x;
};

/** A work order anchored around "today" so it lands in the current calendar period. */
function scheduledToday(overrides = {}) {
  const today = new Date();
  return makeWorkOrder({
    id: "wo_cal",
    title: "Harvest planning",
    status: "TODO",
    start_date: isoDay(today),
    due_date: isoDay(addDays(today, 2)),
    ...overrides,
  });
}

describe("WorkOrdersPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the renamed Work orders heading", async () => {
    renderWithProviders(<WorkOrdersPage />);
    expect(await screen.findByRole("heading", { name: "Work orders" })).toBeInTheDocument();
  });

  it("renders the stats strip", async () => {
    renderWithProviders(<WorkOrdersPage />);
    expect(await screen.findByText("Overdue")).toBeInTheDocument();
    expect(screen.getByText("5")).toBeInTheDocument();
  });

  it("groups work orders into status columns (board view)", async () => {
    renderWithProviders(<WorkOrdersPage />);
    const todo = await screen.findByRole("group", { name: "To do" });
    expect(within(todo).getByText("Bottle Plavac batch")).toBeInTheDocument();

    const inProgress = screen.getByRole("group", { name: "In progress" });
    expect(within(inProgress).getByText("Label batch")).toBeInTheDocument();

    const done = screen.getByRole("group", { name: "Done" });
    expect(within(done).getByText("Ship order")).toBeInTheDocument();
  });

  it("quick-creates a work order (POST body)", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/work-orders`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeWorkOrder({ id: "task_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText("Title"), "Rack barrels");
    await user.click(screen.getByRole("button", { name: "Add work order" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({ title: "Rack barrels", priority: "MEDIUM" });
  });

  it("moves a work order to another status (PATCH body)", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/work-orders/:id/status`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeWorkOrder({ id: String(params.id), status: "DONE" }) });
      }),
    );

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    const todo = await screen.findByRole("group", { name: "To do" });
    await user.selectOptions(within(todo).getByLabelText("Move"), "DONE");

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ status: "DONE" });
  });

  it("reorders work orders by posting the full id list", async () => {
    let posted: { ids?: string[] } | null = null;
    server.use(
      http.get(`${API_URL}/work-orders`, () =>
        HttpResponse.json({
          data: [
            makeWorkOrder({ id: "task_a", title: "Alpha", status: "TODO", sort_order: 1 }),
            makeWorkOrder({ id: "task_b", title: "Beta", status: "TODO", sort_order: 2 }),
          ],
        }),
      ),
      http.post(`${API_URL}/work-orders/reorder`, async ({ request }) => {
        posted = (await request.json()) as { ids?: string[] };
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    const todo = await screen.findByRole("group", { name: "To do" });
    await user.click(within(todo).getAllByRole("button", { name: "Move down" })[0]);

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted!.ids).toEqual(["task_b", "task_a"]);
  });

  it("deletes a work order after confirming", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/work-orders/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    const todo = await screen.findByRole("group", { name: "To do" });
    await user.click(within(todo).getByRole("button", { name: "Delete" }));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(deleted).toBe(true));
  });

  // ── Calendar views ──────────────────────────────────────────────────────────

  it("shows a spanning bar in month view", async () => {
    server.use(
      http.get(`${API_URL}/work-orders`, () => HttpResponse.json({ data: [scheduledToday()] })),
    );

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("tab", { name: "Month" }));
    expect(await screen.findByRole("button", { name: "Harvest planning" })).toBeInTheDocument();
  });

  it("shows a bar in week view", async () => {
    server.use(
      http.get(`${API_URL}/work-orders`, () => HttpResponse.json({ data: [scheduledToday()] })),
    );

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("tab", { name: "Week" }));
    expect(await screen.findByRole("button", { name: "Harvest planning" })).toBeInTheDocument();
  });

  it("lists work orders active on the selected day in day view", async () => {
    server.use(
      http.get(`${API_URL}/work-orders`, () => HttpResponse.json({ data: [scheduledToday()] })),
    );

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("tab", { name: "Day" }));
    expect(await screen.findByRole("button", { name: /Harvest planning/ })).toBeInTheDocument();
  });

  it("navigates day view with prev/next/today", async () => {
    server.use(http.get(`${API_URL}/work-orders`, () => HttpResponse.json({ data: [] })));
    const today = new Date();
    const fmt = new Intl.DateTimeFormat("en", { dateStyle: "medium", timeZone: "Europe/Zagreb" });
    const todayLabel = fmt.format(today);
    const tomorrowLabel = fmt.format(addDays(today, 1));

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("tab", { name: "Day" }));
    expect(screen.getAllByText(todayLabel).length).toBeGreaterThan(0);

    await user.click(screen.getByRole("button", { name: "Next" }));
    expect(screen.getAllByText(tomorrowLabel).length).toBeGreaterThan(0);
    expect(screen.queryByText(todayLabel)).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Today" }));
    expect(screen.getAllByText(todayLabel).length).toBeGreaterThan(0);
  });

  it("opens the detail dialog from a calendar bar and changes status", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.get(`${API_URL}/work-orders`, () => HttpResponse.json({ data: [scheduledToday()] })),
      http.patch(`${API_URL}/work-orders/:id/status`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeWorkOrder({ id: String(params.id), status: "DONE" }) });
      }),
    );

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("tab", { name: "Month" }));
    await user.click(await screen.findByRole("button", { name: "Harvest planning" }));

    const dialog = await screen.findByRole("dialog");
    await user.selectOptions(within(dialog).getByLabelText("Status"), "DONE");

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ status: "DONE" });
  });

  it("renders three month grids in quarter view and picking a day opens day view", async () => {
    server.use(http.get(`${API_URL}/work-orders`, () => HttpResponse.json({ data: [scheduledToday()] })));
    const today = new Date();
    const quarterStartMonth = Math.floor(today.getMonth() / 3) * 3;
    const monthFmt = new Intl.DateTimeFormat("en", { month: "short", year: "numeric", timeZone: "Europe/Zagreb" });
    const firstMonthLabel = monthFmt.format(new Date(today.getFullYear(), quarterStartMonth, 15));

    renderWithProviders(<WorkOrdersPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("tab", { name: "Quarter" }));

    expect(await screen.findByText(firstMonthLabel)).toBeInTheDocument();

    // Picking the day the work order is on jumps to the day view.
    const dayFmt = new Intl.DateTimeFormat("en", { dateStyle: "medium", timeZone: "Europe/Zagreb" });
    const dayCells = screen.getAllByRole("button", { name: dayFmt.format(today) });
    await user.click(dayCells[0]);
    expect(await screen.findByRole("button", { name: /Harvest planning/ })).toBeInTheDocument();
  });
});
