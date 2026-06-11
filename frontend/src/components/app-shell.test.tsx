import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { AppShell } from "./app-shell";
import { API_URL } from "@/lib/config";
import { makeSession } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale } from "@/test/utils";

describe("AppShell nav (plan module gating)", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("hides nav items for modules not in the plan", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () =>
        HttpResponse.json({
          // A basic-style plan: no inflows / costs / cash_flow / suppliers.
          data: makeSession({ modules: ["dashboard", "inventory", "orders", "customers", "team", "settings"] }),
        }),
      ),
    );

    renderWithProviders(
      <AppShell>
        <div>Page</div>
      </AppShell>,
    );

    // In-plan modules appear…
    expect(await screen.findAllByText("Inventory")).not.toHaveLength(0);
    expect(screen.getAllByText("Orders").length).toBeGreaterThan(0);
    // …out-of-plan modules are hidden.
    expect(screen.queryByText("Money in")).not.toBeInTheDocument();
    expect(screen.queryByText("Costs")).not.toBeInTheDocument();
    expect(screen.queryByText("Cash flow")).not.toBeInTheDocument();
    expect(screen.queryByText("Suppliers")).not.toBeInTheDocument();
  });

  it("shows every module when the plan includes them all", async () => {
    renderWithProviders(
      <AppShell>
        <div>Page</div>
      </AppShell>,
    );

    expect(await screen.findAllByText("Costs")).not.toHaveLength(0);
    expect(screen.getAllByText("Cash flow").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Money in").length).toBeGreaterThan(0);
  });
});
