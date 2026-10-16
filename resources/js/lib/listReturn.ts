/**
 * "Back to the list" that really goes back to the list you left: the same search, filters, page and
 * sort. A list page records its own URL as it loads; the detail page's back link reads it, falling
 * back to the bare list when nothing was recorded (a direct link, a new tab).
 *
 * sessionStorage, so it is per tab and survives the visit but not the browser session. Every access
 * is guarded: storage can throw in a private window, and the app must work without it.
 */
const KEY = (list: string): string => `terroir.list-return.${list}`;

export function rememberListUrl(list: string): void {
    try {
        window.sessionStorage.setItem(KEY(list), window.location.pathname + window.location.search);
    } catch {
        // Not remembered; "back" just goes to the bare list.
    }
}

export function listReturnUrl(list: string, fallback: string): string {
    try {
        const stored = window.sessionStorage.getItem(KEY(list));

        // Only ever a path on this site, whatever ended up in storage.
        return stored !== null && stored.startsWith(fallback) ? stored : fallback;
    } catch {
        return fallback;
    }
}
