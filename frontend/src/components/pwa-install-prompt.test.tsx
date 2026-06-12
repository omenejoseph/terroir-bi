import { beforeEach, describe, expect, it, vi } from "vitest";

import { PwaInstallPrompt } from "./pwa-install-prompt";
import { renderWithProviders, screen, seedLocale, userEvent, waitFor } from "@/test/utils";

/** Build a fake `beforeinstallprompt` event with spy-able prompt/userChoice. */
function makeInstallEvent(outcome: "accepted" | "dismissed" = "accepted") {
  const event = new Event("beforeinstallprompt") as Event & {
    prompt: ReturnType<typeof vi.fn>;
    userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
  };
  event.prompt = vi.fn().mockResolvedValue(undefined);
  event.userChoice = Promise.resolve({ outcome });
  return event;
}

describe("PwaInstallPrompt", () => {
  beforeEach(() => {
    seedLocale("en");
    window.localStorage.removeItem("terroir.pwa.install-dismissed");
  });

  it("renders nothing until the browser offers an install prompt", () => {
    const { container } = renderWithProviders(<PwaInstallPrompt />);
    expect(container).toBeEmptyDOMElement();
  });

  it("shows the install banner and fires the native prompt on click", async () => {
    renderWithProviders(<PwaInstallPrompt />);

    const event = makeInstallEvent();
    window.dispatchEvent(event);

    const install = await screen.findByRole("button", { name: "Install app" });
    await userEvent.setup().click(install);

    expect(event.prompt).toHaveBeenCalledOnce();
    await waitFor(() =>
      expect(screen.queryByRole("button", { name: "Install app" })).not.toBeInTheDocument(),
    );
  });

  it("dismisses and stays hidden (remembered per device)", async () => {
    renderWithProviders(<PwaInstallPrompt />);
    window.dispatchEvent(makeInstallEvent());

    await screen.findByText("Install Terroir BI");
    await userEvent.setup().click(screen.getByRole("button", { name: "Dismiss" }));

    expect(screen.queryByText("Install Terroir BI")).not.toBeInTheDocument();
    expect(window.localStorage.getItem("terroir.pwa.install-dismissed")).toBe("true");
  });
});
