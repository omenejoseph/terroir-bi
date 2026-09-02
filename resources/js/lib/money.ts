/**
 * Money crosses the wire as integer MINOR units (cents), matching
 * App\Support\Money\Money on the server. Formatting is the only place that
 * divides — never do arithmetic on the major-unit float.
 */
export function formatMoney(minor: number, currency: string, locale: string): string {
    const fractionDigits = ZERO_DECIMAL.has(currency.toUpperCase()) ? 0 : 2;

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    }).format(minor / 10 ** fractionDigits);
}

/** Currencies whose smallest unit is the major unit, so cents never apply. */
const ZERO_DECIMAL = new Set(['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF']);

export function formatNumber(value: number, locale: string): string {
    return new Intl.NumberFormat(locale).format(value);
}

/**
 * Quantities cross the wire as decimal STRINGS (e.g. "820.000") so precision
 * survives; never parse them for arithmetic. This formats one for display,
 * dropping trailing zeros so a whole number reads as "820", not "820.000".
 */
export function formatQuantity(value: string | number | null, locale: string): string {
    if (value === null || value === '') return '—';

    const n = typeof value === 'number' ? value : Number.parseFloat(value);

    if (!Number.isFinite(n)) return String(value);

    return new Intl.NumberFormat(locale, { maximumFractionDigits: 3 }).format(n);
}
