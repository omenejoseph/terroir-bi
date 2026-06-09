import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import ItemPage from "./page";
import { API_URL } from "@/lib/config";
import { makeItem, makeMovement, makeSession } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

// useParams is mocked in setup to return { id: "itm_1" }.

describe("Inventory item page", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders the item with its stock movements", async () => {
    server.use(
      http.get(`${API_URL}/inventory-items/:id/movements`, () =>
        HttpResponse.json({ data: [makeMovement({ reference: "PO-42", quantity: "25.000" })] }),
      ),
    );

    renderWithProviders(<ItemPage />);
    const user = userEvent.setup();

    expect(await screen.findByRole("heading", { name: "Plavac Mali 2021" })).toBeInTheDocument();
    await user.click(screen.getByRole("tab", { name: "Stock movements" }));

    expect(await screen.findByText("PO-42")).toBeInTheDocument();
    expect(screen.getByText("+25.000")).toBeInTheDocument();
  });

  it("adds a recipe input and saves the recipe", async () => {
    let recipeBody: { items: unknown[] } | null = null;
    server.use(
      http.put(`${API_URL}/inventory-items/:id/recipe`, async ({ request }) => {
        recipeBody = (await request.json()) as { items: unknown[] };
        return HttpResponse.json({ data: [] });
      }),
    );

    renderWithProviders(<ItemPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("tab", { name: "Recipe" }));

    // Managers get a ready search row; open it, search inventory, and select.
    await user.click(await screen.findByLabelText("Input item"));
    await user.type(screen.getByPlaceholderText("Search items…"), "Graš");
    await user.click(await screen.findByRole("button", { name: /Graševina 2022/ }));

    await user.type(screen.getByLabelText("Quantity per unit"), "3");
    await user.click(screen.getByRole("button", { name: "Save recipe" }));

    await waitFor(() => expect(recipeBody).not.toBeNull());
    expect(recipeBody).toEqual({ items: [{ input_id: "itm_2", quantity: 3 }] });
  });

  it("edits the item from the overview section", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/inventory-items/:id`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeItem({ id: String(params.id), name: "Renamed" }) });
      }),
    );

    renderWithProviders(<ItemPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: /Edit/ }));
    const name = await screen.findByLabelText("Name");
    await user.clear(name);
    await user.type(name, "Renamed");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ name: "Renamed" });
  });

  it("hides edit and recipe controls for a viewer", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () =>
        HttpResponse.json({ data: makeSession({ roles: ["CELLAR"] }) }),
      ),
    );

    renderWithProviders(<ItemPage />);
    const user = userEvent.setup();

    // Overview is the default tab; viewers get no Edit button.
    await screen.findByRole("tab", { name: "Overview" });
    expect(screen.queryByRole("button", { name: /Edit/ })).not.toBeInTheDocument();

    // Recipe tab is read-only for viewers — no Add input control.
    await user.click(screen.getByRole("tab", { name: "Recipe" }));
    expect(screen.queryByRole("button", { name: /Add input/ })).not.toBeInTheDocument();
  });
});