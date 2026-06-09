import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import MemberEditPage from "./page";
import { API_URL } from "@/lib/config";
import { makeMember } from "@/test/fixtures";
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

// useParams is mocked in setup to return { userId: "usr_1" }.

describe("MemberEditPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("saves role and status changes", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.get(`${API_URL}/members`, () => HttpResponse.json({ data: [makeMember()] })),
      http.patch(`${API_URL}/members/:userId`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeMember({ user_id: String(params.userId) }) });
      }),
    );

    renderWithProviders(<MemberEditPage />);
    const user = userEvent.setup();

    // Add the TEAM role and suspend.
    await user.click(await screen.findByRole("button", { name: "Team" }));
    await user.selectOptions(screen.getByLabelText("Status"), "suspended");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ status: "suspended" });
    expect((patched as unknown as { roles: string[] }).roles).toEqual(
      expect.arrayContaining(["ADMIN", "TEAM"]),
    );
    expect(mockRouter.push).toHaveBeenCalledWith("/team");
  });

  it("removes the member", async () => {
    let deleted = false;
    server.use(
      http.get(`${API_URL}/members`, () => HttpResponse.json({ data: [makeMember()] })),
      http.delete(`${API_URL}/members/:userId`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<MemberEditPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: /Remove from team/ }));
    await waitFor(() => expect(deleted).toBe(true));
    expect(mockRouter.push).toHaveBeenCalledWith("/team");
  });
});