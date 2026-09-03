import type { NotificationType } from '@/types/notifications';

/**
 * Where clicking a notification should go, ported from the outgoing React
 * app's `resolveNotificationRoute` (`frontend/src/lib/notifications/routes.ts`).
 * `null` means "display only, nothing to open" — the row still gets marked
 * read, it just doesn't navigate.
 */
export function resolveNotificationRoute(type: NotificationType, data: Record<string, string>): string | null {
    switch (type) {
        case 'NEW_ORDER':
        case 'ORDER_STATUS':
        case 'MENTION':
        case 'REPLY':
            // No dedicated Order show route — the list reads ?order= and opens
            // the same drawer this URL lands on directly (see GlobalSearchQuery).
            return data.order_id ? `/orders?order=${data.order_id}` : null;
        case 'AI_IMPORT_READY':
        case 'AI_IMPORT_FAILED':
            // @todo AI Data Entry isn't ported to routes/web.php yet — point
            // this at its page once that module lands.
            return null;
        case 'ANNOUNCEMENT':
            return null;
    }
}

/** A short "2h ago" / "3d ago" style label for a notification's timestamp. */
export function relativeNotificationTime(iso: string | null, locale: string): string {
    if (iso === null) return '';

    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    const steps: [number, Intl.RelativeTimeFormatUnit][] = [
        [60, 'second'],
        [60, 'minute'],
        [24, 'hour'],
        [7, 'day'],
        [4.348, 'week'],
        [12, 'month'],
        [Infinity, 'year'],
    ];

    let value = seconds;
    for (const [size, unit] of steps) {
        if (Math.abs(value) < size) return formatter.format(Math.round(value), unit);

        value /= size;
    }

    return formatter.format(Math.round(value), 'year');
}
