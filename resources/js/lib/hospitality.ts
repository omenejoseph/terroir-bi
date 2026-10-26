import type { ReservationStatus } from '@/types/hospitality';

/**
 * Badge tone per reservation status: the flow walks from outline to solid, money received reads as
 * success, and a cancelled booking is struck through in the destructive tone.
 */
export const RESERVATION_STATUS_VARIANT: Record<ReservationStatus, 'neutral' | 'solid' | 'success' | 'destructive' | 'outline' | 'warning'> = {
    PENDING: 'outline',
    CONFIRMED: 'neutral',
    INVOICE_SENT: 'neutral',
    PAID: 'success',
    COMPLETED: 'solid',
    CANCELLED: 'warning',
};

/**
 * A reservation's wall-clock `YYYY-MM-DD HH:mm`, shown in the reader's locale without any zone
 * shift — the booking is at 11:00 at the winery wherever the reader is.
 */
export function formatReservationDate(value: string, locale: string, withWeekday = false): string {
    const [day, time] = value.split(' ');
    const [y, m, d] = (day ?? '').split('-').map(Number);
    const [hh, mm] = (time ?? '00:00').split(':').map(Number);
    const date = new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1, hh ?? 0, mm ?? 0);

    return date.toLocaleString(locale, {
        weekday: withWeekday ? 'short' : undefined,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** Quarter-hour slots for the time select, 06:00 to 23:45. */
export const TIME_SLOTS: string[] = Array.from({ length: (24 - 6) * 4 }, (_, i) => {
    const minutes = 6 * 60 + i * 15;

    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
});
