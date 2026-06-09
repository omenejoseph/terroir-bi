"use client";

import * as React from "react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import { Card, CardContent } from "@/components/ui/card";
import { Tabs } from "@/components/ui/tabs";
import { GeneralSettings } from "@/components/settings/general-settings";
import { TranslationsEditor } from "@/components/settings/translations-editor";

type SettingsTab = "general" | "translations";

export default function SettingsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const [tab, setTab] = React.useState<SettingsTab>("general");

  if (!can("settings.manage")) {
    return (
      <Card>
        <CardContent className="py-12 text-center text-sm text-muted-foreground">
          {t("settings.forbidden")}
        </CardContent>
      </Card>
    );
  }

  const tabs = [
    { value: "general", label: t("settings.tabs.general") },
    { value: "translations", label: t("settings.tabs.translations") },
  ];

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("settings.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("settings.subtitle")}</p>
      </header>

      <Tabs tabs={tabs} value={tab} onChange={(v) => setTab(v as SettingsTab)} />

      {tab === "general" ? <GeneralSettings /> : <TranslationsEditor />}
    </div>
  );
}
