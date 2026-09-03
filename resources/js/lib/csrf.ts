/**
 * A raw `fetch()` mutation (POST/PUT/PATCH/DELETE) outside Inertia's own
 * axios instance needs its own CSRF header — axios reads the `XSRF-TOKEN`
 * cookie automatically, `fetch` does not. Laravel's `web` middleware group
 * sets that cookie on every response; this just reads it back.
 */
export function csrfHeader(): Record<string, string> {
    const value = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1];

    return value === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(value) };
}
