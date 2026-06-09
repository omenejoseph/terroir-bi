import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import InvitationEditPage from "./page";
import { API_URL } from "@/lib/config";
import { makeInvitation } from "@/test/fixtures";
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

// useParams is mocked in setup to return { invitationId: "inv_1" }.

describe("InvitationEditPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("changes roles and re-issues the link", async () => {
    let body: { email?: string; roles?: string[] } | null = null;
    server.use(
      http.get(`${API_URL}/invitations`, () => HttpResponse.json({ data: [makeInvitation()] })),
      http.post(`${API_URL}/invitations`, async ({ request }) => {
        body = (await request.json()) as { email?: string; roles?: string[] };
        return HttpResponse.json(
          { data: makeInvitation({ id: "inv_1", accept_token: "tok_new" }) },
          { status: 201 },
        );
      }),
    );

    renderWithProviders(<InvitationEditPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: "Admin" })); // add ADMIN
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ email: "newhire@example.com" });
    expect((body as unknown as { roles: string[] }).roles).toEqual(
      expect.arrayContaining(["TEAM", "ADMIN"]),
    );
    expect(await screen.findByText("Invitation updated")).toBeInTheDocument();
    expect(screen.getByDisplayValue(/token=tok_new/)).toBeInTheDocument();
  });

  it("changes the email by revoking the old invite and creating a new one", async () => {
    let revoked = false;
    let body: { email?: string } | null = null;
    server.use(
      http.get(`${API_URL}/invitations`, () => HttpResponse.json({ data: [makeInvitation()] })),
      http.delete(`${API_URL}/invitations/:id`, () => {
        revoked = true;
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(`${API_URL}/invitations`, async ({ request }) => {
        body = (await request.json()) as { email?: string };
        return HttpResponse.json({ data: makeInvitation({ accept_token: "tok2" }) }, { status: 201 });
      }),
    );

    renderWithProviders(<InvitationEditPage />);
    const user = userEvent.setup();

    const email = await screen.findByLabelText("Email");
    await user.clear(email);
    await user.type(email, "changed@example.com");
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(revoked).toBe(true);
    expect(body).toMatchObject({ email: "changed@example.com" });
  });

  it("revokes the invitation and returns to the list", async () => {
    let deleted = false;
    server.use(
      http.get(`${API_URL}/invitations`, () => HttpResponse.json({ data: [makeInvitation()] })),
      http.delete(`${API_URL}/invitations/:id`, () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderWithProviders(<InvitationEditPage />);
    const user = userEvent.setup();

    await user.click(await screen.findByRole("button", { name: /Revoke invitation/ }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /Revoke invitation/ }));
    await waitFor(() => expect(deleted).toBe(true));
    expect(mockRouter.push).toHaveBeenCalledWith("/team");
  });
});