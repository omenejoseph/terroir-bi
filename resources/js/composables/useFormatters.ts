import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import { formatMoney, formatNumber, formatQuantity } from '@/lib/money';
import type { SharedProps } from '@/types';

const DEFAULT_CURRENCY = 'EUR';
const DEFAULT_TIMEZONE = 'Europe/Zagreb';

/**
 * Money is formatted in its currency's own regional convention, independent of
 * the UI language, so a price always reads correctly. The euro, for example,
 * is written with the symbol after the amount and a comma decimal —
 * "1.234,56 €" — across the Eurozone (incl. Croatia), not "€1,234.56".
 */
const CURRENCY_LOCALE: Record<string, string> = {
    EUR: 'hr-HR',
};

function moneyLocale(currencyCode: string, uiLocale: string): string {
    return CURRENCY_LOCALE[currencyCode] ?? uiLocale;
}

/**
 * Locale/currency/timezone-aware formatters, driven by the active locale
 * (`page.props.locale`) and the org's settings (`page.props.org`) — port of
 * the outgoing React app's `useFormatters()`. Money values are integer minor
 * units (cents); `formatMoney`/`formatNumber`/`formatQuantity` (lib/money.ts)
 * stay the underlying primitives, here defaulting their `currency`/`locale`
 * args from shared props instead of a caller having to pass them each time.
 *
 * This is for DISPLAYING already-stored timestamps in the org's timezone —
 * not to be confused with lib/dates.ts, which does calendar-picker arithmetic
 * in local browser time on purpose (a date range a user picks means their
 * calendar days, not UTC instants — a different problem).
 *
 * Stays 100% client-formatted, like the app it replaces: timestamps cross the
 * wire as UTC ISO-8601, and `Intl` + `timeZone` do the conversion here rather
 * than the backend baking a timezone into a response.
 *
 * Every function below reads `page.props` at CALL time (like lib/money.ts's
 * own functions), not at setup time — so a persisted layout component (e.g.
 * AppSidebar, which doesn't remount between Inertia visits) still reflects a
 * locale/org change without needing its own watcher.
 */
export function useFormatters() {
    const page = usePage<SharedProps>();

    const locale = computed(() => page.props.locale);
    const currency = computed(() => page.props.org?.default_currency || DEFAULT_CURRENCY);
    const timeZone = computed(() => page.props.org?.timezone || DEFAULT_TIMEZONE);

    function curLocale(): string {
        return moneyLocale(currency.value, locale.value);
    }

    return {
        locale,
        currency,
        timeZone,
        /** Plain number in the active locale. */
        number: (n: number) => formatNumber(n, locale.value),
        /** A decimal-string quantity (see lib/money.ts) in the active locale. */
        quantity: (value: string | number | null) => formatQuantity(value, locale.value),
        /** Minor units → currency, in the org's currency and its own regional convention. */
        money: (minor: number) => formatMoney(minor, currency.value, curLocale()),
        /** Minor units → currency, always 2 decimals (matches the prototype). */
        money2: (minor: number) =>
            new Intl.NumberFormat(curLocale(), { style: 'currency', currency: currency.value }).format(minor / 100),
        /** Minor units → compact currency for chart axes (e.g. €15K). */
        moneyAxis: (minor: number) =>
            new Intl.NumberFormat(curLocale(), {
                style: 'currency',
                currency: currency.value,
                notation: 'compact',
                maximumFractionDigits: 0,
            }).format(minor / 100),
        /** ISO/date → medium date in the org timezone. */
        date: (value: string | number | Date) =>
            new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeZone: timeZone.value }).format(
                new Date(value),
            ),
        /** ISO/date → medium date + short time in the org timezone. */
        dateTime: (value: string | number | Date) =>
            new Intl.DateTimeFormat(locale.value, {
                dateStyle: 'medium',
                timeStyle: 'short',
                timeZone: timeZone.value,
            }).format(new Date(value)),
        /** ISO/date → "September 2026". */
        monthYear: (value: string | number | Date) =>
            new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric', timeZone: timeZone.value }).format(
                new Date(value),
            ),
        /** ISO/date → "Sep 2026". */
        monthShort: (value: string | number | Date) =>
            new Intl.DateTimeFormat(locale.value, { month: 'short', year: 'numeric', timeZone: timeZone.value }).format(
                new Date(value),
            ),
        /** A number of days ago → locale phrase, e.g. "2 days ago" / "yesterday" / "today". */
        relativeDays: (days: number) => {
            const rtf = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' });
            if (days <= 0) return rtf.format(0, 'day');
            if (days < 45) return rtf.format(-days, 'day');
            const months = Math.round(days / 30);
            if (months < 18) return rtf.format(-months, 'month');
            return rtf.format(-Math.round(days / 365), 'year');
        },
    };
}
