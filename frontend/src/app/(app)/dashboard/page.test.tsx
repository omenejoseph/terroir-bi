import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import DashboardPage from "./page";
import { API_URL } from "@/lib/config";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale } from "@/test/utils";

describe("DashboardPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("greets the authenticated user and shows session info", async () => {
    renderWithProviders(<DashboardPage />);

    expect(await screen.findByText("Welcome back, Ada")).toBeInTheDocument();
    expect(screen.getByText("ada@example.com")).toBeInTheDocument();
    // "owner" appears in both the roles stat and the session badge.
    expect(screen.getAllByText("owner").length).toBeGreaterThan(0);
  });

  it("shows the inventory item count from the API", async () => {
    server.use(
      http.get(`${API_URL}/inventory-items`, () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 5 },
        }),
      ),
    );

    renderWithProviders(<DashboardPage />);

    expect(await screen.findByText("Inventory items")).toBeInTheDocument();
    expect(await screen.findByText("5")).toBeInTheDocument();
  });
});