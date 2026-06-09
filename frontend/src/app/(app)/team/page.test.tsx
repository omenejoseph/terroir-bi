import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import TeamPage from "./page";
import { API_URL } from "@/lib/config";
import { makeInvitation, makeMember } from "@/test/fixtures";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
  within,
} from "@/test/utils";

describe("TeamPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("lists members and pending invitations", async () => {
    renderWithProviders(<TeamPage />);
    expect(await screen.findByText("Ada Lovelace")).toBeInTheDocument();
    expect(screen.getByText("Members")).toBeInTheDocument();
    expect(await screen.findByText("newhire@example.com")).toBeInTheDocument();
  });

  it("navigates to the invite page", async () => {
    renderWithProviders(<TeamPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: /Invite member/ }));
    expect(mockRouter.push).toHaveBeenCalledWith("/team/invite");
  });

  it("expands a member inline with edit and remove actions", async () => {
    renderWithProviders(<TeamPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Ada Lovelace"));
    expect(await screen.findByRole("button", { name: /Edit/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Remove from team/ })).toBeInTheDocument();
    expect(mockRouter.push).not.toHaveBeenCalled();
  });

  it("edits a member inline and saves", async () => {
    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch(`${API_URL}/members/:userId`, async ({ request, params }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: makeMember({ user_id: String(params.userId) }) });
      }),
    );

    renderWithProviders(<TeamPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("Ada Lovelace"));
    await user.click(await screen.findByRole("button", { name: /Edit/ }));
    await user.selectOptions(await screen.findByLabelText("Status"), "suspended");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({ status: "suspended" });
  });

  it("expands an invitation with edit, regenerate and revoke actions", async () => {
    renderWithProviders(<TeamPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("newhire@example.com"));
    expect(await screen.findByRole("button", { name: /Regenerate link/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Revoke" })).toBeInTheDocument();
    expect(mockRouter.push).not.toHaveBeenCalled();
  });

  it("regenerates an invitation link inline", async () => {
    server.use(
      http.post(`${API_URL}/invitations`, () =>
        HttpResponse.json({ data: makeInvitation({ accept_token: "tok_regen" }) }, { status: 201 }),
      ),
    );

    renderWithProviders(<TeamPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("newhire@example.com"));
    await user.click(await screen.findByRole("button", { name: /Regenerate link/ }));
    expect(await screen.findByDisplayValue(/token=tok_regen/)).toBeInTheDocument();
  });

  it("revokes a pending invitation", async () => {
    let revoked = false;
    server.use(
      http.delete(`${API_URL}/invitations/:id`, () => {
        revoked = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<TeamPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByText("newhire@example.com"));
    await user.click(await screen.findByRole("button", { name: "Revoke" }));
    // Confirm in the dialog.
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Revoke" }));
    await waitFor(() => expect(revoked).toBe(true));
  });

  it("shows a permission error on 403", async () => {
    server.use(http.get(`${API_URL}/members`, () => HttpResponse.json({ message: "Forbidden." }, { status: 403 })));
    renderWithProviders(<TeamPage />);
    expect(await screen.findByText("You don't have permission to manage the team.")).toBeInTheDocument();
  });

  it("hides the invite control when the user lacks permission", async () => {
    server.use(
      http.get(`${API_URL}/auth/me`, () =>
        HttpResponse.json({
          data: {
            token: "tok",
            user: { id: "u", first_name: "C", middle_name: null, last_name: "X", name: "C X", email: "c@x.com" },
            active_tenant_id: "ten_a",
            roles: ["CELLAR"],
            tenants: [],
          },
        }),
      ),
    );

    renderWithProviders(<TeamPage />);
    await screen.findByText("Members");
    expect(screen.queryByRole("button", { name: /Invite member/ })).not.toBeInTheDocument();
  });
});