import { http, HttpResponse } from "msw";
import { beforeEach, describe, expect, it } from "vitest";

import LoginPage from "./page";
import { API_URL, STORAGE_KEYS } from "@/lib/config";
import { mockRouter } from "@/test/setup";
import { server } from "@/test/mocks/server";
import { renderWithProviders, screen, seedLocale, userEvent, waitFor } from "@/test/utils";

const url = (path: string) => `${API_URL}${path}`;

async function fillAndSubmit() {
  const user = userEvent.setup();
  await user.type(await screen.findByLabelText("Email"), "ada@example.com");
  await user.type(screen.getByLabelText("Password"), "secret123");
  await user.click(screen.getByRole("button", { name: "Sign in" }));
  return user;
}

describe("LoginPage", () => {
  beforeEach(() => seedLocale("en"));

  it("renders the sign-in form", async () => {
    renderWithProviders(<LoginPage />);
    expect(await screen.findByLabelText("Email")).toBeInTheDocument();
    expect(screen.getByLabelText("Password")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sign in" })).toBeInTheDocument();
  });

  it("logs in, stores the token and redirects to the dashboard", async () => {
    renderWithProviders(<LoginPage />);
    await fillAndSubmit();

    await waitFor(() =>
      expect(window.localStorage.getItem(STORAGE_KEYS.token)).toBe("tok_new"),
    );
    expect(mockRouter.replace).toHaveBeenCalledWith("/dashboard");
  });

  it("shows the server validation message on a 422", async () => {
    server.use(
      http.post(url("/auth/login"), () =>
        HttpResponse.json(
          { message: "These credentials do not match.", errors: { email: ["Invalid credentials."] } },
          { status: 422 },
        ),
      ),
    );

    renderWithProviders(<LoginPage />);
    await fillAndSubmit();

    expect(await screen.findByText("Invalid credentials.")).toBeInTheDocument();
    expect(window.localStorage.getItem(STORAGE_KEYS.token)).toBeNull();
    expect(mockRouter.replace).not.toHaveBeenCalled();
  });

  it("shows a generic error when the request fails (network/500)", async () => {
    server.use(http.post(url("/auth/login"), () => HttpResponse.error()));

    renderWithProviders(<LoginPage />);
    await fillAndSubmit();

    expect(
      await screen.findByText("Unable to sign in. Check your connection and try again."),
    ).toBeInTheDocument();
    expect(mockRouter.replace).not.toHaveBeenCalled();
  });
});