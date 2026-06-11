import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { AccessGuard } from "./access-guard";
import { API_URL } from "@/lib/config";
import { makeAccess, makeSession } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale } from "@/test/utils";

function seedAccess(level: "full" | "read_only" | "blocked", daysRemaining: number | null = null) {
  server.use(
    http.get(`${API_URL}/auth/me`, () =>
      HttpResponse.json({ data: makeSession({ access: makeAccess({ level, days_remaining: daysRemaining }) }) }),
    ),
  );
}

describe("AccessGuard", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("renders children unchanged when access is full", async () => {
    seedAccess("full");
    renderWithProviders(
      <AccessGuard>
        <div>Inner content</div>
      </AccessGuard>,
    );

    expect(await screen.findByText("Inner content")).toBeInTheDocument();
    expect(screen.queryByText("Read-only mode")).not.toBeInTheDocument();
  });

  it("shows a banner above the page in read-only grace", async () => {
    seedAccess("read_only", 5);
    renderWithProviders(
      <AccessGuard>
        <div>Inner content</div>
      </AccessGuard>,
    );

    expect(await screen.findByText("Read-only mode")).toBeInTheDocument();
    expect(screen.getByText(/5 days remaining/)).toBeInTheDocument();
    // The page still renders behind the banner.
    expect(screen.getByText("Inner content")).toBeInTheDocument();
  });

  it("replaces the page with the blocked screen when blocked", async () => {
    seedAccess("blocked");
    renderWithProviders(
      <AccessGuard>
        <div>Inner content</div>
      </AccessGuard>,
    );

    expect(await screen.findByText("Subscription expired")).toBeInTheDocument();
    expect(screen.queryByText("Inner content")).not.toBeInTheDocument();
  });
});
