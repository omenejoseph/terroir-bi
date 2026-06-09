import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import OrderAnalyticsPage from "./page";
import { API_URL } from "@/lib/config";
import { makeSession } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale } from "@/test/utils";

describe("OrderAnalyticsPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders KPI cards", async () => {
    renderWithProviders(<OrderAnalyticsPage />);
    expect(await screen.findByText("Gross profit")).toBeInTheDocument();
    expect(screen.getByText("Avg order value")).toBeInTheDocument();
    expect(screen.getByText("Top customers")).toBeInTheDocument();
    expect(screen.getByText("Low-margin orders")).toBeInTheDocument();
  });

  it("blocks users without financials.view", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () =>
        HttpResponse.json({ data: makeSession({ roles: ["CELLAR"] }) }),
      ),
    );
    renderWithProviders(<OrderAnalyticsPage />);
    expect(
      await screen.findByText("You don't have permission to view financials."),
    ).toBeInTheDocument();
  });
});
