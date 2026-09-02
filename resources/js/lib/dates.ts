/**
 * Calendar arithmetic for the date picker.
 *
 * Everything here works on `YYYY-MM-DD` strings and LOCAL dates. A date range a
 * user picks means their calendar days, not UTC instants — using `toISOString()`
 * would shift "1 Aug" to "31 Jul" for anyone west of Greenwich, which is the
 * classic off-by-one in every hand-rolled date picker.
 */

/** `YYYY-MM-DD` for a local date, without going through UTC. */
export function toIsoDate(date: Date): string {
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** Parse `YYYY-MM-DD` as a local midnight. `new Date(str)` would parse it as UTC. */
export function fromIsoDate(value: string | null): Date | null {
    if (value === null || value === '') return null;

    const [year, month, day] = value.split('-').map(Number);

    if (!year || !month || !day) return null;

    return new Date(year, month - 1, day);
}

export function startOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

export function addMonths(date: Date, months: number): Date {
    // Clamp the day so 31 Jan + 1 month is 28/29 Feb rather than 2/3 Mar.
    const target = new Date(date.getFullYear(), date.getMonth() + months, 1);
    const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();

    return new Date(target.getFullYear(), target.getMonth(), Math.min(date.getDate(), lastDay));
}

export function isSameDay(a: Date | null, b: Date | null): boolean {
    return a !== null && b !== null && toIsoDate(a) === toIsoDate(b);
}

/**
 * Which weekday a locale's week starts on, 0 = Sunday.
 *
 * `Intl.Locale.getWeekInfo()` knows this and Chromium implements it; where it
 * is missing we fall back to Monday, which is right for this product's markets
 * (Croatia and the EU) and is the safer wrong answer than Sunday.
 */
export function firstDayOfWeek(locale: string): number {
    try {
        const info = (new Intl.Locale(locale) as unknown as { getWeekInfo?: () => { firstDay: number } })
            .getWeekInfo?.();

        // getWeekInfo uses ISO numbering: 1 = Monday … 7 = Sunday.
        if (info) return info.firstDay % 7;
    } catch {
        // Unsupported locale string — fall through.
    }

    return 1;
}

/**
 * The 6×7 grid a month is drawn on, padded with the surrounding months' days so
 * every month has the same height and the layout never jumps.
 */
export function monthGrid(month: Date, locale: string): { date: Date; inMonth: boolean }[] {
    const first = startOfMonth(month);
    const offset = (first.getDay() - firstDayOfWeek(locale) + 7) % 7;
    const start = new Date(first.getFullYear(), first.getMonth(), 1 - offset);

    return Array.from({ length: 42 }, (_, i) => {
        const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);

        return { date, inMonth: date.getMonth() === month.getMonth() };
    });
}

/** Localised weekday initials, starting on the locale's first day. */
export function weekdayLabels(locale: string): string[] {
    const formatter = new Intl.DateTimeFormat(locale, { weekday: 'short' });
    const start = firstDayOfWeek(locale);

    return Array.from({ length: 7 }, (_, i) => {
        // 2024-01-07 was a Sunday, so this walks a real week in order.
        const date = new Date(2024, 0, 7 + ((start + i) % 7));

        return formatter.format(date);
    });
}

/** Inclusive, order-insensitive containment test used for range shading. */
export function isWithin(date: Date, from: Date | null, to: Date | null): boolean {
    if (from === null || to === null) return false;

    const [lo, hi] = from <= to ? [from, to] : [to, from];
    const iso = toIsoDate(date);

    return iso >= toIsoDate(lo) && iso <= toIsoDate(hi);
}
