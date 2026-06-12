"use client";

import * as React from "react";
import { useRouter } from "next/navigation";

import { resolveNotificationRoute } from "@/lib/notifications/routes";
import type { NotificationType } from "@/lib/types";

/**
 * The app's single reaction point for an opened push notification. The service
 * worker is routing-dumb: it either postMessages { type, data } to a focused
 * client, or opens the app with a ?notif= param. Here we resolve (type, data) to
 * a route and navigate — keeping all web paths in resolveNotificationRoute.
 */
export function NotificationClickHandler() {
  const router = useRouter();

  const go = React.useCallback(
    (type: NotificationType | null, data: Record<string, string> | null) => {
      if (!type) return;
      const route = resolveNotificationRoute(type, data);
      if (route) router.push(route);
    },
    [router],
  );

  // Clicks while the app is open arrive as SW messages.
  React.useEffect(() => {
    if (!("serviceWorker" in navigator)) return;
    const onMessage = (event: MessageEvent) => {
      const payload = event.data;
      if (payload && payload.kind === "notification-click") {
        go(payload.type ?? null, payload.data ?? null);
      }
    };
    navigator.serviceWorker.addEventListener("message", onMessage);
    return () => navigator.serviceWorker.removeEventListener("message", onMessage);
  }, [go]);

  // Cold start: the SW opened the app with the payload encoded in ?notif=.
  React.useEffect(() => {
    if (typeof window === "undefined") return;
    const params = new URLSearchParams(window.location.search);
    const raw = params.get("notif");
    if (!raw) return;
    try {
      const { type, data } = JSON.parse(raw);
      go(type ?? null, data ?? null);
    } catch {
      // Ignore a malformed param.
    }
    // Clean the URL so a refresh doesn't re-navigate.
    params.delete("notif");
    const qs = params.toString();
    window.history.replaceState({}, "", window.location.pathname + (qs ? `?${qs}` : ""));
  }, [go]);

  return null;
}
