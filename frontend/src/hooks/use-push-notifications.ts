"use client";

import * as React from "react";

import {
  getPushSubscribed,
  isPushSupported,
  subscribeToPush,
  unsubscribeFromPush,
} from "@/lib/push";

type Permission = NotificationPermission | "unsupported";

/**
 * Drives the "enable notifications" UI: feature support, the current browser
 * permission, whether this device is subscribed, and enable/disable actions.
 * Used by both the notifications bell and the Settings page.
 */
export function usePushNotifications() {
  const supported = React.useMemo(() => isPushSupported(), []);
  const [permission, setPermission] = React.useState<Permission>("default");
  const [isSubscribed, setIsSubscribed] = React.useState(false);
  const [busy, setBusy] = React.useState(false);

  React.useEffect(() => {
    if (!supported) {
      setPermission("unsupported");
      return;
    }
    setPermission(Notification.permission);
    void getPushSubscribed().then(setIsSubscribed);
  }, [supported]);

  const enable = React.useCallback(async () => {
    if (!supported || busy) return;
    setBusy(true);
    try {
      const result = await Notification.requestPermission();
      setPermission(result);
      if (result !== "granted") return;
      await subscribeToPush();
      setIsSubscribed(true);
    } finally {
      setBusy(false);
    }
  }, [supported, busy]);

  const disable = React.useCallback(async () => {
    if (!supported || busy) return;
    setBusy(true);
    try {
      await unsubscribeFromPush();
      setIsSubscribed(false);
    } finally {
      setBusy(false);
    }
  }, [supported, busy]);

  return { supported, permission, isSubscribed, enable, disable, busy };
}
