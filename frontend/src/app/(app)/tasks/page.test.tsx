import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import TasksPage from "./page";
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

describe("TasksPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the stats strip", async () => {
    renderWithProviders(<TasksPage />);
    // makeWorkOrderStats: todo 2, in_progress 1, done 5, overdue 1.
    expect(await screen.findByText("Overdue")).toBeInTheDocument();
    // done count 5 is unique to the stats strip.
    expect(screen.getByText("5")).toBeInTheDocument();
  });

  it("groups tasks into status columns", async () => {
    renderWithProviders(<TasksPage />);
    // Default list: task_1 TODO, task_2 IN_PROGRESS, task_3 DONE.
    const todo = await screen.findByRole("group", { name: "To do" });
    expect(within(todo).getByText("Bottle Plavac batch")).toBeInTheDocument();

    const inProgress = screen.getByRole("group", { name: "In progress" });
    expect(within(inProgress).getByText("Label batch")).toBeInTheDocument();

    const done = screen.getByRole("group", { name: "Done" });
    expect(within(done).getByText("Ship order")).toBeInTheDocument();
  });

  it("quick-creates a task (POST body)", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.post(`${API_URL}/work-orders`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeWorkOrder({ id: "task_new" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<TasksPage />);
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText("Title"), "Rack barrels");
    await user.click(screen.getByRole("button", { name: "Add task" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({ title: "Rack barrels", priority: "MEDIUM" });
  });

  it("moves a task to another status (PATCH body)", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/work-orders/:id/status`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeWorkOrder({ id: String(params.id), status: "DONE" }) });
      }),
    );

    renderWithProviders(<TasksPage />);
    const user = userEvent.setup();
    const todo = await screen.findByRole("group", { name: "To do" });
    await user.selectOptions(within(todo).getByLabelText("Move"), "DONE");

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ status: "DONE" });
  });

  it("reorders tasks by posting the full id list", async () => {
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

    renderWithProviders(<TasksPage />);
    const user = userEvent.setup();
    const todo = await screen.findByRole("group", { name: "To do" });
    // Only Alpha (first) has an enabled Move-down; clicking it swaps it past Beta.
    await user.click(within(todo).getAllByRole("button", { name: "Move down" })[0]);

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted!.ids).toEqual(["task_b", "task_a"]);
  });

  it("deletes a task after confirming", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/work-orders/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<TasksPage />);
    const user = userEvent.setup();
    const todo = await screen.findByRole("group", { name: "To do" });
    await user.click(within(todo).getByRole("button", { name: "Delete" }));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(deleted).toBe(true));
  });
});
