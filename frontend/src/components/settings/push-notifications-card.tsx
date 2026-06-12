"use client";

import { Bell, BellOff } from "lucide-react";

import { usePushNotifications } from "@/hooks/use-push-notifications";
import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/** iOS only allows web push for an installed (Home Screen) PWA. */
function isIos(): boolean {
  if (typeof navigator === "undefined") return false;
  return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

/**
 * Per-device push toggle. A personal (not organisation) preference, so it's shown
 * to every user regardless of settings permissions.
 */
export function PushNotificationsCard() {
  const { t } = useTranslation();
  const { supported, permission, isSubscribed, enable, disable, busy } = usePushNotifications();

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{t("notifications.push.title")}</CardTitle>
        <CardDescription>{t("notifications.push.description")}</CardDescription>
      </CardHeader>
      <CardContent className="flex items-center justify-between gap-4">
        <p className="text-sm text-muted-foreground">
          {!supported
            ? isIos()
              ? t("notifications.push.iosHint")
              : t("notifications.push.unsupported")
            : permission === "denied"
              ? t("notifications.push.denied")
              : isSubscribed
                ? t("notifications.push.enabled")
                : t("notifications.push.description")}
        </p>

        {supported && permission !== "denied" && (
          isSubscribed ? (
            <Button variant="outline" size="sm" disabled={busy} onClick={() => void disable()}>
              <BellOff className="size-4" />
              {busy ? t("notifications.push.busy") : t("notifications.push.disable")}
            </Button>
          ) : (
            <Button size="sm" disabled={busy} onClick={() => void enable()}>
              <Bell className="size-4" />
              {busy ? t("notifications.push.busy") : t("notifications.push.enable")}
            </Button>
          )
        )}
      </CardContent>
    </Card>
  );
}
