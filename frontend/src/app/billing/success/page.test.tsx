import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import BillingSuccessPage from "./page";
import { API_URL } from "@/lib/config";
import { makeAccess, makeSession } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale } from "@/test/utils";

describe("BillingSuccessPage", () => {
  beforeEach(() => {
    seedLocale("en");
  });

  it("confirms the subscription once /auth/me reports full access", async () => {
    seedAuth();
    server.use(
      http.get(`${API_URL}/auth/me`, () =>
        HttpResponse.json({ data: makeSession({ access: makeAccess({ level: "full" }) }) }),
      ),
    );

    renderWithProviders(<BillingSuccessPage />);

    expect(await screen.findByText("Subscription active")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Go to dashboard" }),
    ).toHaveAttribute("href", "/dashboard");
  });

  it("shows the guest confirmation and a sign-in link when not authenticated", async () => {
    renderWithProviders(<BillingSuccessPage />);

    expect(await screen.findByText("Subscription active")).toBeInTheDocument();
    expect(screen.getByText("Your checkout is complete. Sign in to continue.")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Sign in" })).toHaveAttribute("href", "/login");
  });
});
