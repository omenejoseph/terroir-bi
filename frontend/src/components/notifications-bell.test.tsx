import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import { NotificationsBell } from "./notifications-bell";
import { API_URL } from "@/lib/config";
import { makeNotification } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedAuth, seedLocale, userEvent, waitFor } from "@/test/utils";

describe("NotificationsBell", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("shows the unread count and lists notifications", async () => {
    renderWithProviders(<NotificationsBell />);
    // Unread badge.
    expect(await screen.findByText("1")).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole("button", { name: "Notifications" }));
    expect(await screen.findByText("New order ORD-1001")).toBeInTheDocument();
  });

  it("marks all as read", async () => {
    let marked = false;
    server.use(
      http.post(`${API_URL}/notifications/read`, () => {
        marked = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<NotificationsBell />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Notifications" }));
    await user.click(await screen.findByText("Mark all read"));
    await waitFor(() => expect(marked).toBe(true));
  });
});
