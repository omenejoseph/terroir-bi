"use client";

import * as React from "react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { GeneralSettings } from "@/components/settings/general-settings";
import { InstallAppCard } from "@/components/settings/install-app-card";
import { PushNotificationsCard } from "@/components/settings/push-notifications-card";

export default function SettingsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("settings.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("settings.subtitle")}</p>
      </header>

      {/* Per-device app install — available to every user. */}
      <InstallAppCard />

      {/* Per-device push preference — available to every user. */}
      <PushNotificationsCard />

      {/* Organisation settings — admin only. Translations are managed in the back office. */}
      {can("settings.manage") ? (
        <GeneralSettings />
      ) : (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            {t("settings.forbidden")}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
