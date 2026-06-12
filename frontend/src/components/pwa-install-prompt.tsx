"use client";

import * as React from "react";
import { Download, X } from "lucide-react";

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

  return (
    <div className="mb-4 flex items-center gap-3 rounded-lg border border-border bg-primary/5 px-4 py-3">
      <Download className="size-5 shrink-0 text-primary" />
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium">{t("install.title")}</p>
        <p className="text-xs text-muted-foreground">
          {iosHint ? t("install.iosHint") : t("install.description")}
        </p>
      </div>
      {deferred && (
        <Button size="sm" onClick={() => void install()}>
          {t("install.action")}
        </Button>
      )}
      <Button variant="ghost" size="icon" aria-label={t("common.dismiss")} onClick={dismiss}>
        <X className="size-4" />
      </Button>
    </div>
  );
}