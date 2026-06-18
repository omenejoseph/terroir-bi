"use client";

import { Check, Download, SquarePlus } from "lucide-react";

import { useInstallPrompt } from "@/hooks/use-install-prompt";
import { useTranslation } from "@/i18n/context";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * Persistent install entry, mirroring the push card. Always available in Settings
 * so a user who dismissed or missed the one-time banner can still install — or
 * follow the manual Share steps on iOS. Reads the shared install state, so the
 * native prompt captured early in <head> is reused here.
 */
export function InstallAppCard() {
  const { t } = useTranslation();
  const { available, installed, ios, promptInstall } = useInstallPrompt();

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{t("install.settingsTitle")}</CardTitle>
        <CardDescription>{t("install.settingsDescription")}</CardDescription>
      </CardHeader>
      <CardContent className="flex items-center justify-between gap-4">
        <p className="text-sm text-muted-foreground">
          {installed
            ? t("install.installed")
            : ios
              ? t("install.iosLead")
              : available
                ? t("install.settingsDescription")
                : t("install.unsupported")}
        </p>

        {installed ? (
          <Check className="size-5 shrink-0 text-primary" aria-hidden />
        ) : ios ? (
          // iOS can't install programmatically — show the Share glyph as a cue.
          <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
            {t("install.iosStep2")}
            <SquarePlus className="size-4 shrink-0 text-primary" aria-hidden />
          </span>
        ) : (
          available && (
            <Button size="sm" onClick={() => void promptInstall()}>
              <Download className="size-4" />
              {t("install.action")}
            </Button>
          )
        )}
      </CardContent>
    </Card>
  );
}