"use client";

import * as React from "react";

import { translationsApi } from "@/lib/api/translations";
import { DEFAULT_LOCALE, STORAGE_KEYS, SUPPORTED_LOCALES, type Locale } from "@/lib/config";
import { MESSAGES } from "@/i18n/config";

type Vars = Record<string, string | number>;

interface I18nContextValue {
  locale: Locale;
  locales: readonly Locale[];
  setLocale: (locale: Locale) => void;
  /** Translate a dot-path key, e.g. t("inventory.title"), with {var} interpolation. */
  t: (key: string, vars?: Vars) => string;
  /** Tenant overrides currently applied for the active locale (key → value). */
  overrides: Record<string, string>;
  /** Re-fetch overrides for the active locale (e.g. after editing them). */
  refreshOverrides: () => Promise<void>;
}

const I18nContext = React.createContext<I18nContextValue | null>(null);

function readStoredLocale(): Locale {
  if (typeof window === "undefined") return DEFAULT_LOCALE;
  const stored = window.localStorage.getItem(STORAGE_KEYS.locale);
  return SUPPORTED_LOCALES.includes(stored as Locale) ? (stored as Locale) : DEFAULT_LOCALE;
}

/** Resolve a dot-path against a nested catalog object. */
function lookup(catalog: unknown, key: string): string | undefined {
  let node: unknown = catalog;
  for (const part of key.split(".")) {
    if (node && typeof node === "object" && part in (node as Record<string, unknown>)) {
      node = (node as Record<string, unknown>)[part];
    } else {
      return undefined;
    }
  }
  return typeof node === "string" ? node : undefined;
}

function interpolate(template: string, vars?: Vars): string {
  if (!vars) return template;
  return template.replace(/\{(\w+)\}/g, (match, name: string) =>
    name in vars ? String(vars[name]) : match,
  );
}

export function I18nProvider({ children }: { children: React.ReactNode }) {
  const [locale, setLocaleState] = React.useState<Locale>(DEFAULT_LOCALE);
  // Tenant-managed overrides take precedence over the bundled catalog.
  const [overrides, setOverrides] = React.useState<Record<string, string>>({});

  // Hydrate locale from storage on mount (avoids SSR/client mismatch).
  React.useEffect(() => {
    setLocaleState(readStoredLocale());
  }, []);

  // Reflect locale onto <html lang> and storage; client.ts reads the same key.
  React.useEffect(() => {
    if (typeof window === "undefined") return;
    window.localStorage.setItem(STORAGE_KEYS.locale, locale);
    document.documentElement.lang = locale;
  }, [locale]);

  // Pull tenant overrides for the active locale; failures fall back to bundle.
  React.useEffect(() => {
    let cancelled = false;
    translationsApi
      .overrides(locale)
      .then((map) => {
        if (!cancelled) setOverrides(map);
      })
      .catch(() => {
        if (!cancelled) setOverrides({});
      });
    return () => {
      cancelled = true;
    };
  }, [locale]);

  const refreshOverrides = React.useCallback(async () => {
    try {
      setOverrides(await translationsApi.overrides(locale));
    } catch {
      setOverrides({});
    }
  }, [locale]);

  const setLocale = React.useCallback((next: Locale) => {
    if (SUPPORTED_LOCALES.includes(next)) setLocaleState(next);
  }, []);

  const t = React.useCallback(
    (key: string, vars?: Vars) => {
      const template = overrides[key] ?? lookup(MESSAGES[locale], key) ?? key;
      return interpolate(template, vars);
    },
    [locale, overrides],
  );

  const value = React.useMemo<I18nContextValue>(
    () => ({ locale, locales: SUPPORTED_LOCALES, setLocale, t, overrides, refreshOverrides }),
    [locale, setLocale, t, overrides, refreshOverrides],
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useTranslation(): I18nContextValue {
  const ctx = React.useContext(I18nContext);
  if (!ctx) throw new Error("useTranslation must be used within <I18nProvider>");
  return ctx;
}