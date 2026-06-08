"use client";

import { Languages } from "lucide-react";

import { LOCALE_LABELS } from "@/i18n/config";
import { useTranslation } from "@/i18n/context";
import type { Locale } from "@/lib/config";
import { cn } from "@/lib/utils";

/** Compact segmented language toggle. Persisted + sent to the API as X-Locale. */
export function LanguageSwitcher({ className }: { className?: string }) {
  const { locale, locales, setLocale, t } = useTranslation();

  return (
    <div className={cn("flex items-center gap-2", className)}>
      <Languages className="size-4 text-muted-foreground" aria-hidden />
      <span className="sr-only">{t("common.language")}</span>
      <div className="inline-flex rounded-md border border-border p-0.5">
        {locales.map((loc: Locale) => (
          <button
            key={loc}
            type="button"
            onClick={() => setLocale(loc)}
            aria-pressed={loc === locale}
            className={cn(
              "rounded-sm px-2 py-1 text-xs font-medium transition-colors",
              loc === locale
                ? "bg-primary text-primary-foreground"
                : "text-muted-foreground hover:text-foreground",
            )}
          >
            {LOCALE_LABELS[loc]}
          </button>
        ))}
      </div>
    </div>
  );
}