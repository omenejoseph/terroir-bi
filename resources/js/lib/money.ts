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
