import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { PwaInstallPrompt } from "./pwa-install-prompt";
import { renderWithProviders, screen, seedLocale, userEvent, waitFor } from "@/test/utils";

/**
 * Simulate the early-capture script in <head>: stash a fake `beforeinstallprompt`
 * event on `window` and announce availability, exactly as production does before
 * React hydrates. Returns the event so tests can assert on its spies.
 */
function offerInstall(outcome: "accepted" | "dismissed" = "accepted") {
  const event = new Event("beforeinstallprompt") as Event & {
    prompt: ReturnType<typeof vi.fn>;
    userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
  };
  event.prompt = vi.fn().mockResolvedValue(undefined);
  event.userChoice = Promise.resolve({ outcome });
  (window as unknown as { __terroirInstallPrompt: unknown }).__terroirInstallPrompt = event;
  window.dispatchEvent(new Event("terroir:installavailable"));
  return event;
}

describe("PwaInstallPrompt", () => {
  beforeEach(() => {
    seedLocale("en");
    window.localStorage.removeItem("terroir.pwa.install-dismissed");
  });

  afterEach(() => {
    (window as unknown as { __terroirInstallPrompt: unknown }).__terroirInstallPrompt = null;
  });

  it("renders nothing until the browser offers an install prompt", () => {
    const { container } = renderWithProviders(<PwaInstallPrompt />);
    expect(container).toBeEmptyDOMElement();
  });

  it("shows the install banner and fires the native prompt on click", async () => {
    renderWithProviders(<PwaInstallPrompt />);

    const event = offerInstall();

    const install = await screen.findByRole("button", { name: "Install app" });
    await userEvent.setup().click(install);

    expect(event.prompt).toHaveBeenCalledOnce();
    await waitFor(() =>
      expect(screen.queryByRole("button", { name: "Install app" })).not.toBeInTheDocument(),
    );
  });

  it("dismisses and stays hidden (remembered per device)", async () => {
    renderWithProviders(<PwaInstallPrompt />);
    offerInstall();

    await screen.findByText("Install Terroir BI");
    await userEvent.setup().click(screen.getByRole("button", { name: "Dismiss" }));

    expect(screen.queryByText("Install Terroir BI")).not.toBeInTheDocument();
    expect(window.localStorage.getItem("terroir.pwa.install-dismissed")).toBe("true");
  });

  it("shows the Share-sheet guide (no install button) on iOS", async () => {
    const original = navigator.userAgent;
    Object.defineProperty(navigator, "userAgent", {
      value: "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15",
      configurable: true,
    });
    try {
      renderWithProviders(<PwaInstallPrompt />);
      // iOS gets the step-by-step guide, never a programmatic install button.
      expect(await screen.findByText("Tap the Share button")).toBeInTheDocument();
      expect(screen.getByText("Choose “Add to Home Screen”")).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: "Install app" })).not.toBeInTheDocument();
    } finally {
      Object.defineProperty(navigator, "userAgent", { value: original, configurable: true });
    }
  });
});
