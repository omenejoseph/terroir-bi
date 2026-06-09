import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import SettingsPage from "./page";
import { API_URL } from "@/lib/config";
import { makeSession, makeSettings } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

describe("SettingsPage — General", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("loads settings and shows the currency read-only", async () => {
    renderWithProviders(<SettingsPage />);
    const name = await screen.findByLabelText("Organisation name");
    expect((name as HTMLInputElement).value).toBe("Vinarija Alpha");
    const currency = screen.getByLabelText("Currency") as HTMLInputElement;
    expect(currency.value).toBe("EUR");
    expect(currency).toBeDisabled();
  });

  it("saves changes without sending currency", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/settings`, async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeSettings(body) });
      }),
    );

    renderWithProviders(<SettingsPage />);
    const name = await screen.findByLabelText("Organisation name");
    await userEvent.setup().clear(name);
    await userEvent.setup().type(name, "Renamed Winery");
    await userEvent.setup().click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ name: "Renamed Winery", default_locale: "hr", timezone: "Europe/Zagreb" });
    expect(body).not.toHaveProperty("default_currency");
    expect(await screen.findByText("Saved")).toBeInTheDocument();
  });

  it("blocks users without the settings.manage capability", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () =>
        HttpResponse.json({ data: makeSession({ roles: ["CELLAR"] }) }),
      ),
    );

    renderWithProviders(<SettingsPage />);
    expect(
      await screen.findByText("You don't have permission to manage settings."),
    ).toBeInTheDocument();
  });
});

describe("SettingsPage — Translations", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("overrides an entry by its label (PUT) and then reverts it (DELETE)", async () => {
    let put: { locale?: string; key?: string; value?: string } | null = null;
    let deleted: { locale?: string; key?: string } | null = null;
    server.use(
      http.put(`${API_URL}/translations`, async ({ request }) => {
        put = (await request.json()) as { locale: string; key: string; value: string };
        return HttpResponse.json({ data: { id: "ovr_1", ...put } });
      }),
      http.delete(`${API_URL}/translations`, async ({ request }) => {
        deleted = (await request.json()) as { locale: string; key: string };
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<SettingsPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("tab", { name: "Translations" }));

    // Filter by the human label — the technical key is never shown.
    await user.type(await screen.findByPlaceholderText("Search text…"), "Total Orders");
    const input = await screen.findByLabelText("Total Orders");
    await user.clear(input);
    await user.type(input, "Orders placed");
    await user.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(put).not.toBeNull());
    // The key is still sent to the API behind the scenes.
    expect(put).toMatchObject({
      locale: "en",
      key: "dashboard.stats.totalOrders",
      value: "Orders placed",
    });

    await user.click(await screen.findByRole("button", { name: /Revert: Total Orders/ }));
    await waitFor(() => expect(deleted).not.toBeNull());
    expect(deleted).toMatchObject({ locale: "en", key: "dashboard.stats.totalOrders" });
  });
});
