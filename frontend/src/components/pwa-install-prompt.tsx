"use client";

import * as React from "react";
import { ChevronDown, Download, SquarePlus, X } from "lucide-react";

import { useTranslation } from "@/i18n/context";
import { useInstallPrompt } from "@/hooks/use-install-prompt";
import { Button } from "@/components/ui/button";

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
  const { available, installed, ios, promptInstall } = useInstallPrompt();
  // Assume hidden until mounted so the server/first paint never flashes a banner.
  const [dismissed, setDismissed] = React.useState(true);

  React.useEffect(() => {
    setDismissed(window.localStorage.getItem(DISMISS_KEY) === "true");
  }, []);

  const dismiss = React.useCallback(() => {
    setDismissed(true);
    window.localStorage.setItem(DISMISS_KEY, "true");
  }, []);

  const install = React.useCallback(async () => {
    await promptInstall();
    // Hide regardless of choice; the Settings card remains for a later attempt.
    setDismissed(true);
  }, [promptInstall]);

  // iOS shows the manual Share hint; other platforms need a captured prompt.
  const iosHint = ios && !installed;

  // Nothing actionable to show: dismissed/installed, or no install path yet.
  if (dismissed || installed || (!available && !iosHint)) return null;

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