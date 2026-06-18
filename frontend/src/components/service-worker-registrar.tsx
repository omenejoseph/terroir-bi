"use client";

import * as React from "react";

/**
 * Registers the PWA service worker (public/sw.js) in production and keeps open
 * clients current. Every deploy ships a byte-different worker (its cache version
 * is stamped with the build id), so when the user returns to the app we check
 * for a new version and reload once it has taken control. Without this, a warm
 * iOS tab or installed PWA can keep running stale JS until its cache is manually
 * cleared — which is most painful on mobile, where the app matters most.
 */
export function ServiceWorkerRegistrar() {
  React.useEffect(() => {
    if (process.env.NODE_ENV !== "production") return;
    if (!("serviceWorker" in navigator)) return;

    let registration: ServiceWorkerRegistration | undefined;
    let updateReady = false;
    let reloading = false;

    // A newly activated worker claims this page (skipWaiting + clientsClaim in
    // sw.js). Reload to pick up its fresh HTML + hashed chunks — but only for a
    // real update, not the first-ever install (which has no prior controller).
    const onControllerChange = () => {
      if (!updateReady || reloading) return;
      reloading = true;
      window.location.reload();
    };

    const watchInstalling = (reg: ServiceWorkerRegistration) => {
      const sw = reg.installing;
      if (!sw) return;
      sw.addEventListener("statechange", () => {
        if (sw.state === "installed" && navigator.serviceWorker.controller) {
          updateReady = true;
        }
      });
    };

    const register = async () => {
      try {
        // updateViaCache:"none" → the browser revalidates sw.js against the
        // network instead of serving it from the HTTP cache, so update checks
        // actually see a newly deployed worker.
        const reg = await navigator.serviceWorker.register("/sw.js", {
          updateViaCache: "none",
        });
        registration = reg;
        reg.addEventListener("updatefound", () => watchInstalling(reg));
      } catch {
        // Registration failures shouldn't break the app.
      }
    };

    // Check for a new deploy each time the user returns to the app.
    const onVisible = () => {
      if (document.visibilityState === "visible") registration?.update().catch(() => {});
    };

    navigator.serviceWorker.addEventListener("controllerchange", onControllerChange);
    document.addEventListener("visibilitychange", onVisible);
    // This effect frequently runs *after* window 'load' has already fired (React
    // hydration lands late on a fast static page), so a bare load listener would
    // never call register and the SW — and this whole update flow — would silently
    // never start. Register now if the page is already loaded; otherwise wait.
    if (document.readyState === "complete") {
      void register();
    } else {
      window.addEventListener("load", register);
    }

    return () => {
      navigator.serviceWorker.removeEventListener("controllerchange", onControllerChange);
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("load", register);
    };
  }, []);

  return null;
}
