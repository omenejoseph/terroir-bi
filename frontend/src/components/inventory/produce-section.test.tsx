import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { ProduceSection } from "./produce-section";
import { API_URL } from "@/lib/config";
import { makeItem, makeRecipeLine } from "@/test/fixtures";
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

const item = makeItem();

describe("ProduceSection", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
    server.use(
      http.get(`${API_URL}/inventory-items/:id/recipe`, () =>
        HttpResponse.json({ data: [makeRecipeLine()] }),
      ),
    );
  });

  it("lists the materials needed with group and available stock", async () => {
    renderWithProviders(<ProduceSection item={item} canManage />);

    expect(await screen.findByText(/Materials needed for 1 bottle/)).toBeInTheDocument();
    expect(screen.getByText(/Graševina 2022/)).toBeInTheDocument();
    expect(screen.getByText(/\(Wine\)/)).toBeInTheDocument();
    expect(screen.getByText("have: 500")).toBeInTheDocument();
    expect(screen.getByText("2 bottle")).toBeInTheDocument(); // 2 per bottle × 1
  });

  it("scales the materials with the quantity", async () => {
    renderWithProviders(<ProduceSection item={item} canManage />);
    const user = userEvent.setup();

    const qty = await screen.findByLabelText(/Quantity to produce/);
    await user.clear(qty);
    await user.type(qty, "3");

    expect(await screen.findByText("6 bottle")).toBeInTheDocument(); // 2 × 3
  });

  it("runs a production after confirming", async () => {
    let produced: { display_quantity?: number } | null = null;
    server.use(
      http.post(`${API_URL}/inventory-items/:id/produce`, async ({ request }) => {
        produced = (await request.json()) as { display_quantity?: number };
        return HttpResponse.json({ data: makeItem() });
      }),
    );

    renderWithProviders(<ProduceSection item={item} canManage />);
    const user = userEvent.setup();

    const qty = await screen.findByLabelText(/Quantity to produce/);
    await user.clear(qty);
    await user.type(qty, "10");
    await user.click(screen.getByRole("button", { name: "Produce" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Confirm" }));

    await waitFor(() => expect(produced).not.toBeNull());
    expect(produced).toMatchObject({ display_quantity: 10 });
  });

  it("surfaces an insufficient-stock 422", async () => {
    server.use(
      http.post(`${API_URL}/inventory-items/:id/produce`, () =>
        HttpResponse.json(
          { message: "Not enough stock.", errors: { quantity: ["Not enough stock for Graševina."] } },
          { status: 422 },
        ),
      ),
    );

    renderWithProviders(<ProduceSection item={item} canManage />);
    const user = userEvent.setup();

    const qty = await screen.findByLabelText(/Quantity to produce/);
    await user.clear(qty);
    await user.type(qty, "999");
    await user.click(screen.getByRole("button", { name: "Produce" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Confirm" }));

    expect(await screen.findByText("Not enough stock for Graševina.")).toBeInTheDocument();
  });
});
