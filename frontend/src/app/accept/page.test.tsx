import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import AcceptInvitationPage from "./page";
import { API_URL } from "@/lib/config";
import { makeSession } from "@/test/fixtures";
import { mockRouter, mockSearchParams } from "@/test/setup";
import { server } from "@/test/mocks/server";
import {
  renderWithProviders,
  screen,
  seedLocale,
  userEvent,
  waitFor,
} from "@/test/utils";

describe("AcceptInvitationPage", () => {
  beforeEach(() => {
    seedLocale("en");
    mockSearchParams.set("token", "tok_invite");
  });

  it("warns when the link has no token", async () => {
    mockSearchParams.delete("token");
    renderWithProviders(<AcceptInvitationPage />);
    expect(
      await screen.findByText("This invitation link is missing its token."),
    ).toBeInTheDocument();
  });

  it("accepts directly for an existing account and redirects", async () => {
    let body: { token?: string } | null = null;
    server.use(
      http.post(`${API_URL}/auth/invitations/accept`, async ({ request }) => {
        body = (await request.json()) as { token?: string };
        return HttpResponse.json({ data: makeSession({ token: "tok_accept" }) });
      }),
    );

    renderWithProviders(<AcceptInvitationPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Accept invitation" }));

    await waitFor(() => expect(body).toMatchObject({ token: "tok_invite" }));
    await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith("/dashboard"));
  });

  it("reveals the profile form for a new account, then creates it", async () => {
    let call = 0;
    const sent: Record<string, unknown>[] = [];
    server.use(
      http.post(`${API_URL}/auth/invitations/accept`, async ({ request }) => {
        const payload = (await request.json()) as Record<string, unknown>;
        sent.push(payload);
        call += 1;
        // First (token-only) attempt → backend asks for profile.
        if (call === 1) {
          return HttpResponse.json(
            { message: "The password field is required.", errors: { password: ["Required."] } },
            { status: 422 },
          );
        }
        return HttpResponse.json({ data: makeSession({ token: "tok_accept" }) });
      }),
    );

    renderWithProviders(<AcceptInvitationPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Accept invitation" }));

    // Profile form appears.
    await user.type(await screen.findByLabelText("First name"), "Pat");
    await user.type(screen.getByLabelText("Last name"), "Smith");
    await user.type(screen.getByLabelText("Password"), "supersecret");
    await user.click(screen.getByRole("button", { name: "Create account & join" }));

    await waitFor(() => expect(call).toBe(2));
    expect(sent[1]).toMatchObject({
      token: "tok_invite",
      first_name: "Pat",
      last_name: "Smith",
      password: "supersecret",
    });
    await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith("/dashboard"));
  });

  it("shows an error for an invalid token", async () => {
    server.use(
      http.post(`${API_URL}/auth/invitations/accept`, () =>
        HttpResponse.json(
          { message: "Invalid.", errors: { token: ["This invitation is invalid."] } },
          { status: 422 },
        ),
      ),
    );

    renderWithProviders(<AcceptInvitationPage />);
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Accept invitation" }));

    expect(
      await screen.findByText("This invitation link is invalid or has expired."),
    ).toBeInTheDocument();
  });
});