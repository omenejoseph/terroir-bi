"use client";

import { Languages } from "lucide-react";

import { LOCALE_LABELS } from "@/i18n/config";
import { useTranslation } from "@/i18n/context";
import type { Locale } from "@/lib/config";
import { cn } from "@/lib/utils";
import { Tabs } from "@/components/ui/tabs";

/** Compact segmented language toggle. Persisted + sent to the API as X-Locale. */
export function LanguageSwitcher({ className }: { className?: string }) {
  const { locale, locales, setLocale, t } = useTranslation();

  return (
    <div className={cn("flex items-center gap-2", className)}>
      <Languages className="size-4 text-muted-foreground" aria-hidden />
      <span className="sr-only">{t("common.language")}</span>
      <Tabs
        tabs={locales.map((loc: Locale) => ({ value: loc, label: LOCALE_LABELS[loc] }))}
        value={locale}
        onChange={(v) => setLocale(v as Locale)}
      />
    </div>
  );
}