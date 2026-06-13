"use client";

import * as React from "react";
import { ChevronDown, Download, SquarePlus, X } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";

/**
 * The browser's `beforeinstallprompt` event — not part of the standard DOM lib,
 * so we describe the shape we use.
 */
interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
}

const DISMISS_KEY = "terroir.pwa.install-dismissed";

/** The iOS Share glyph (square with an up-arrow) — matches Safari's toolbar icon. */
function IosShareIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      className={className}
    >
      <path d="M12 3v12" />
      <path d="m8 7 4-4 4 4" />
      <path d="M8 11H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-2" />
    </svg>
  );
}

/** iOS Safari has no `beforeinstallprompt`; install is always manual there. */
function isIos(): boolean {
  if (typeof navigator === "undefined") return false;
  return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

/** Already launched as an installed PWA (so there's nothing to install). */
function isStandalone(): boolean {
  if (typeof window === "undefined") return false;
  return (
    window.matchMedia?.("(display-mode: standalone)").matches === true ||
    // iOS Safari exposes installed state here instead of matchMedia.
    (navigator as unknown as { standalone?: boolean }).standalone === true
  );
}

/**
 * Proactively invites the user to install the PWA.
 *
 * Chrome/Edge/Android: captures `beforeinstallprompt`, suppresses the browser's
 * own mini-infobar, and shows an "Install app" button that fires the native
 * prompt on click. iOS Safari: no such event exists, so we show a one-line
 * Share → Add to Home Screen hint instead.
 *
 * Renders nothing when already installed or once the user dismisses it (the
 * dismissal is remembered per device).
 */
export function PwaInstallPrompt() {
  const { t } = useTranslation();
  const [deferred, setDeferred] = React.useState<BeforeInstallPromptEvent | null>(null);
  const [iosHint, setIosHint] = React.useState(false);
  // Assume hidden until mounted so the server/first paint never flashes a banner.
  const [dismissed, setDismissed] = React.useState(true);

  React.useEffect(() => {
    if (isStandalone()) return;
    if (window.localStorage.getItem(DISMISS_KEY) === "true") return;
    setDismissed(false);

    // iOS can't fire the install event — offer the manual hint and stop.
    if (isIos()) {
      setIosHint(true);
      return;
    }

    const onBeforeInstall = (e: Event) => {
      e.preventDefault(); // we render our own affordance instead of the infobar
      setDeferred(e as BeforeInstallPromptEvent);
    };
    const onInstalled = () => {
      setDeferred(null);
      setDismissed(true);
    };

    window.addEventListener("beforeinstallprompt", onBeforeInstall);
    window.addEventListener("appinstalled", onInstalled);
    return () => {
      window.removeEventListener("beforeinstallprompt", onBeforeInstall);
      window.removeEventListener("appinstalled", onInstalled);
    };
  }, []);

  const dismiss = React.useCallback(() => {
    setDismissed(true);
    window.localStorage.setItem(DISMISS_KEY, "true");
  }, []);

  const install = React.useCallback(async () => {
    if (!deferred) return;
    await deferred.prompt();
    await deferred.userChoice;
    // The captured event is single-use — drop it and hide regardless of choice.
    setDeferred(null);
    setDismissed(true);
  }, [deferred]);

  // Nothing actionable to show: dismissed/installed, or no install path yet.
  if (dismissed || (!deferred && !iosHint)) return null;

  // iOS can't install programmatically — guide the user to Safari's Share button
  // with a coachmark pinned to the bottom (where that toolbar button lives),
  // pointing down at it.
  if (iosHint) {
    return (
      <div className="fixed inset-x-0 bottom-0 z-50 px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        <div className="mx-auto max-w-md rounded-xl border border-border bg-popover p-4 shadow-lg">
          <div className="flex items-start justify-between gap-3">
            <p className="text-sm font-medium">{t("install.title")}</p>
            <button
              type="button"
              aria-label={t("common.dismiss")}
              onClick={dismiss}
              className="-m-1 rounded-md p-1 text-muted-foreground hover:text-foreground"
            >
              <X className="size-4" />
            </button>
          </div>
          <p className="mt-0.5 text-xs text-muted-foreground">{t("install.iosLead")}</p>
          <ol className="mt-3 space-y-2 text-sm">
            <li className="flex items-center gap-2">
              <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                1
              </span>
              <span className="inline-flex items-center gap-1">
                {t("install.iosStep1")}
                <IosShareIcon className="size-4 shrink-0 text-primary" />
              </span>
            </li>
            <li className="flex items-center gap-2">
              <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                2
              </span>
              <span className="inline-flex items-center gap-1">
                {t("install.iosStep2")}
                <SquarePlus className="size-4 shrink-0 text-primary" />
              </span>
            </li>
          </ol>
          {/* Arrow pointing down at Safari's Share button in the toolbar below. */}
          <ChevronDown className="mx-auto mt-2 size-5 animate-bounce text-primary" aria-hidden />
        </div>
      </div>
    );
  }

  // Android / desktop: a real one-tap install button.
  return (
    <div className="mb-4 flex items-center gap-3 rounded-lg border border-border bg-primary/5 px-4 py-3">
      <Download className="size-5 shrink-0 text-primary" />
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium">{t("install.title")}</p>
        <p className="text-xs text-muted-foreground">{t("install.description")}</p>
      </div>
      <Button size="sm" onClick={() => void install()}>
        {t("install.action")}
      </Button>
      <Button variant="ghost" size="icon" aria-label={t("common.dismiss")} onClick={dismiss}>
        <X className="size-4" />
      </Button>
    </div>
  );
}