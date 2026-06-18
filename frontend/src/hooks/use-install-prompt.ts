"use client";

import * as React from "react";

/**
 * The browser's `beforeinstallprompt` event — not part of the standard DOM lib,
 * so we describe the shape we use.
 */
export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
}

declare global {
  interface Window {
    /**
     * The most recent `beforeinstallprompt` event, stashed by the early-capture
     * script injected in <head> (see app/layout.tsx). Chrome fires that event
     * before React hydrates, so a `useEffect` listener would miss it — we grab it
     * synchronously in <head> and read it back here.
     */
    __terroirInstallPrompt?: BeforeInstallPromptEvent | null;
  }
}

/** Custom events the <head> script dispatches so React can react to capture/install. */
export const INSTALL_AVAILABLE_EVENT = "terroir:installavailable";
export const INSTALLED_EVENT = "terroir:installed";

/** iOS Safari has no `beforeinstallprompt`; install is always manual there. */
export function isIos(): boolean {
  if (typeof navigator === "undefined") return false;
  return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

/** Already launched as an installed PWA (so there's nothing to install). */
export function isStandalone(): boolean {
  if (typeof window === "undefined") return false;
  return (
    window.matchMedia?.("(display-mode: standalone)").matches === true ||
    // iOS Safari exposes installed state here instead of matchMedia.
    (navigator as unknown as { standalone?: boolean }).standalone === true
  );
}

export interface InstallPromptState {
  /** A native install prompt is captured and ready to fire (Chromium only). */
  available: boolean;
  /** Running as an installed PWA, or just installed this session. */
  installed: boolean;
  /** iOS Safari — install is manual via Share → Add to Home Screen. */
  ios: boolean;
  /** Fires the captured native prompt; resolves to the user's choice (or null). */
  promptInstall: () => Promise<"accepted" | "dismissed" | null>;
}

/**
 * Single source of truth for PWA install state, shared by the dismissable banner
 * and the persistent Settings card.
 *
 * Reads the event captured early in <head> (so we don't miss Chrome's pre-hydration
 * `beforeinstallprompt`), then keeps in sync via the custom availability/installed
 * events the same script dispatches.
 */
export function useInstallPrompt(): InstallPromptState {
  const [available, setAvailable] = React.useState(false);
  const [installed, setInstalled] = React.useState(false);
  // Resolved on the client only, post-mount, to avoid hydration mismatches.
  const [ios, setIos] = React.useState(false);

  React.useEffect(() => {
    if (isStandalone()) {
      setInstalled(true);
      return;
    }
    setIos(isIos());
    if (window.__terroirInstallPrompt) setAvailable(true);

    const onAvailable = () => setAvailable(true);
    const onInstalled = () => {
      setAvailable(false);
      setInstalled(true);
    };
    window.addEventListener(INSTALL_AVAILABLE_EVENT, onAvailable);
    window.addEventListener(INSTALLED_EVENT, onInstalled);
    return () => {
      window.removeEventListener(INSTALL_AVAILABLE_EVENT, onAvailable);
      window.removeEventListener(INSTALLED_EVENT, onInstalled);
    };
  }, []);

  const promptInstall = React.useCallback(async () => {
    const event = window.__terroirInstallPrompt;
    if (!event) return null;
    await event.prompt();
    const { outcome } = await event.userChoice;
    // The captured event is single-use — drop it so we don't re-fire a stale one.
    window.__terroirInstallPrompt = null;
    setAvailable(false);
    return outcome;
  }, []);

  return { available, installed, ios, promptInstall };
}