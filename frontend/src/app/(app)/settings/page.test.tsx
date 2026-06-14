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
