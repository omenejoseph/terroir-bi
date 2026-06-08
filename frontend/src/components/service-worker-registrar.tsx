"use client";

import * as React from "react";

/**
 * Registers the PWA service worker (public/sw.js) in production. Kept as a tiny
 * client component so the rest of the tree stays server-renderable. The SW gives
 * the app installability + an offline app shell.
 */
export function ServiceWorkerRegistrar() {
  React.useEffect(() => {
    if (process.env.NODE_ENV !== "production") return;
    if (!("serviceWorker" in navigator)) return;

    const register = () => {
      navigator.serviceWorker.register("/sw.js").catch(() => {
        // Registration failures shouldn't break the app.
      });
    };

    window.addEventListener("load", register);
    return () => window.removeEventListener("load", register);
  }, []);

  return null;
}