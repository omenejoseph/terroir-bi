import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import InviteMemberPage from "./page";
import { API_URL } from "@/lib/config";
import { makeInvitation } from "@/test/fixtures";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedAuth,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

describe("InviteMemberPage", () => {
  beforeEach(() => {
    seedAuth();
    seedLocale("en");
  });

  it("invites a member and shows the accept link", async () => {
    let body: { email?: string; roles?: string[] } | null = null;
    server.use(
      http.post(`${API_URL}/invitations`, async ({ request }) => {
        body = (await request.json()) as { email?: string; roles?: string[] };
        return HttpResponse.json(
          { data: makeInvitation({ id: "inv_new", accept_token: "tok_abc123" }) },
          { status: 201 },
        );
      }),
    );

    renderWithProviders(<InviteMemberPage />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText("Email"), "newhire@example.com");
    await user.click(screen.getByRole("button", { name: "Cellar" }));
    await user.click(screen.getByRole("button", { name: "Send invitation" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ email: "newhire@example.com", roles: ["CELLAR"] });
    // Accept link with the one-time token is shown.
    expect(await screen.findByText("Invitation created")).toBeInTheDocument();
    expect(screen.getByDisplayValue(/token=tok_abc123/)).toBeInTheDocument();
  });

  it("disables submit until a role is chosen", async () => {
    renderWithProviders(<InviteMemberPage />);
    const user = userEvent.setup();
    await user.type(screen.getByLabelText("Email"), "x@y.com");
    expect(screen.getByRole("button", { name: "Send invitation" })).toBeDisabled();
  });
});