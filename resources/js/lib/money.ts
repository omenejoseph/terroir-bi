/**
 * Money crosses the wire as integer MINOR units (cents), matching
 * App\Support\Money\Money on the server. Formatting is the only place that
 * divides — never do arithmetic on the major-unit float.
 *
 * Money is formatted in ONE fixed convention, independent of the viewer's UI
 * language — an amount is a fact about the business, not a translation, so it
 * must not read differently to an English-UI viewer than a Croatian-UI one.
 * `MONEY_FORMAT` is that one convention, and the only thing worth overriding
 * from `Intl`'s own `hr-HR` data (which otherwise gets grouping/decimal
 * separators right) is where the currency symbol sits: this org leads with it
 * ("€ 7.071,69"), not trails it ("7.071,69 €"). When a differently-formatted
 * country's book is added, this is the one place to change — either adjust
 * `MONEY_FORMAT` directly, or turn it into a lookup once there is more than
 * one convention to choose between.
 */
const MONEY_FORMAT = {
    /** The base locale for digit grouping and the decimal separator. */
    locale: 'hr-HR',
    /** Where the currency symbol sits relative to the number. */
    symbolPosition: 'before' as 'before' | 'after',
};

/** Currencies whose smallest unit is the major unit, so cents never apply. */
const ZERO_DECIMAL = new Set(['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF']);

/**
 * The shared engine behind every currency formatter here (and
 * `useFormatters`'s `money2`/`moneyAxis`, which need their own decimal/
 * notation options): let `Intl.NumberFormat` do the locale-correct digit
 * grouping and decimal separator, then reposition only the symbol.
 */
export function formatCurrencyParts(amount: number, options: Intl.NumberFormatOptions): string {
    const parts = new Intl.NumberFormat(MONEY_FORMAT.locale, {
        ...options,
        style: 'currency',
        currencyDisplay: 'symbol',
    }).formatToParts(amount);

    // The symbol and the whitespace joining it to the number are the only two
    // part types this touches — grouping/decimal separators, digits and the
    // minus sign are left exactly as Intl's own locale data produced them.
    const symbol = parts.find((p) => p.type === 'currency')?.value ?? String(options.currency ?? '');
    const gap = parts.find((p) => p.type === 'literal')?.value ?? '';
    const number = parts
        .filter((p) => p.type !== 'currency' && p.type !== 'literal')
        .map((p) => p.value)
        .join('');

    return MONEY_FORMAT.symbolPosition === 'before' ? `${symbol}${gap}${number}` : `${number}${gap}${symbol}`;
}

export function formatMoney(minor: number, currency: string): string {
    const fractionDigits = ZERO_DECIMAL.has(currency.toUpperCase()) ? 0 : 2;

    return formatCurrencyParts(minor / 10 ** fractionDigits, {
        currency,
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    });
}

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
