import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import CustomerDetailPage from "./page";
import { API_URL } from "@/lib/config";
import { makeCustomer } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
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

describe("CustomerDetailPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("loads the customer and saves edits", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/customers/:id`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeCustomer({ id: String(params.id) }) });
      }),
    );

    renderWithProviders(<CustomerDetailPage />);
    const user = userEvent.setup();

    const name = await screen.findByLabelText("Company name");
    expect((name as HTMLInputElement).value).toBe("Acme Corporation");

    await user.clear(name);
    await user.type(name, "Acme Renamed");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ company_name: "Acme Renamed" });
    expect(mockRouter.push).toHaveBeenCalledWith("/customers");
  });

  it("deletes the customer (admin) and returns to the list", async () => {
    let deleted = false;
    server.use(
      http.delete(`${API_URL}/customers/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<CustomerDetailPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: /Delete/ }));
    await waitFor(() => expect(deleted).toBe(true));
    expect(mockRouter.push).toHaveBeenCalledWith("/customers");
  });
});