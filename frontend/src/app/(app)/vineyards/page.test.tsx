import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import VineyardsPage from "./page";
import { API_URL } from "@/lib/config";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

const parcel = {
  id: "parcel_1",
  name: "Dingač Block A",
  ownership: "OWN" as const,
  grape_variety: "Plavac Mali",
  area_hectares: "1.25",
  vine_count: 4200,
};

describe("VineyardsPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("lists parcels", async () => {
    server.use(http.get(`${API_URL}/vineyard-parcels`, () => HttpResponse.json({ data: [parcel] })));

    renderWithProviders(<VineyardsPage />);

    await waitFor(() => expect(screen.getByText("Dingač Block A")).toBeInTheDocument());
    expect(screen.getByText("Plavac Mali")).toBeInTheDocument();
  });

  it("creates a parcel and routes to its detail page", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.get(`${API_URL}/vineyard-parcels`, () => HttpResponse.json({ data: [] })),
      http.post(`${API_URL}/vineyard-parcels`, async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: { ...parcel, id: "parcel_new" } }, { status: 201 });
      }),
    );

    renderWithProviders(<VineyardsPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: "Add parcel" }));
    // The dialog holds two text inputs: name, then grape variety.
    const [nameInput, grapeInput] = screen.getAllByRole("textbox");
    await user.type(nameInput, "New Block");
    await user.type(grapeInput, "Pošip");
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({ name: "New Block", grape_variety: "Pošip" });
    expect(mockRouter.push).toHaveBeenCalledWith("/vineyards/parcels/parcel_new");
  });
});
