"use client";

import * as React from "react";

import { useAuth } from "@/lib/auth/context";
import { useTranslation } from "@/i18n/context";
import type { Money } from "@/lib/types";

const DEFAULT_CURRENCY = "EUR";
const DEFAULT_TIMEZONE = "Europe/Zagreb";

/**
 * Money is formatted in its currency's own regional convention, independent of
 * the UI language, so a price always reads correctly. The euro, for example,
 * is written with the symbol after the amount and a comma decimal — "1.234,56 €"
 * — across the Eurozone (incl. Croatia), not "€1,234.56".
 */
const CURRENCY_LOCALE: Record<string, string> = {
  EUR: "hr-HR",
};

function moneyLocale(currencyCode: string, uiLocale: string): string {
  return CURRENCY_LOCALE[currencyCode] ?? uiLocale;
}

/**
 * Locale/currency/timezone-aware formatters derived from the active locale and
 * the organisation settings. Money values are integer minor units (cents).
 *
 * Use this instead of constructing `Intl.*` formatters with a hardcoded currency
 * so the whole app honours the org's chosen currency and timezone.
 */
export function useFormatters() {
  const { locale } = useTranslation();
  const { settings } = useAuth();

  const currency = settings?.default_currency || DEFAULT_CURRENCY;
  const timeZone = settings?.timezone || DEFAULT_TIMEZONE;

  return React.useMemo(() => {
    const num = new Intl.NumberFormat(locale);
    const curLocale = moneyLocale(currency, locale);
    const cur0 = new Intl.NumberFormat(curLocale, {
      style: "currency",
      currency,
      maximumFractionDigits: 0,
    });
    const cur2 = new Intl.NumberFormat(curLocale, { style: "currency", currency });
    const curCompact = new Intl.NumberFormat(curLocale, {
      style: "currency",
      currency,
      notation: "compact",
      maximumFractionDigits: 0,
    });
    const dateFmt = new Intl.DateTimeFormat(locale, { dateStyle: "medium", timeZone });
    const dateTimeFmt = new Intl.DateTimeFormat(locale, {
      dateStyle: "medium",
      timeStyle: "short",
      timeZone,
    });

    return {
      currency,
      timeZone,
      /** Plain number in the active locale. */
      number: (n: number) => num.format(n),
      /** Minor units → currency, no fractional part (e.g. totals). */
      money: (minor: number) => cur0.format(minor / 100),
      /** Minor units → currency with decimals. */
      money2: (minor: number) => cur2.format(minor / 100),
      /** Minor units → compact currency for chart axes (e.g. €15K). */
      moneyAxis: (minor: number) => curCompact.format(minor / 100),
      /**
       * Format a Money object in its own currency's regional convention (e.g.
       * "12,34 €") so it always reads as money. Uses the value's own currency.
       */
      moneyObject: (m: Money | null): string => {
        if (!m) return "—";
        return new Intl.NumberFormat(moneyLocale(m.currency, locale), {
          style: "currency",
          currency: m.currency,
        }).format(m.minor / 100);
      },
      /** ISO/date → medium date in the org timezone. */
      date: (value: string | number | Date) => dateFmt.format(new Date(value)),
      /** ISO/date → medium date + short time in the org timezone. */
      dateTime: (value: string | number | Date) => dateTimeFmt.format(new Date(value)),
    };
  }, [locale, currency, timeZone]);
}
