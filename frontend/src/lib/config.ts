/**
 * Runtime configuration. The frontend's only hard dependency on the backend is
 * this base URL — everything else is plain HTTP. Set NEXT_PUBLIC_API_URL in
 * .env.local (see .env.local.example).
 */
export const API_URL =
  process.env.NEXT_PUBLIC_API_URL ?? "http://localhost/api/v1";

export const APP_NAME = process.env.NEXT_PUBLIC_APP_NAME ?? "Terroir BI";

/**
 * VAPID public key for Web Push (generate with `php artisan push:vapid`). Empty
 * when push isn't configured — the UI then treats push as unsupported.
 */
export const VAPID_PUBLIC_KEY = process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY ?? "";

/**
 * Supported locales. Must stay in sync with the backend's
 * config('app.supported_locales'). Croatian-first, matching the API default.
 */
export const SUPPORTED_LOCALES = ["hr", "en"] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];
export const DEFAULT_LOCALE: Locale = "hr";

/** localStorage keys for persisted client state. */
export const STORAGE_KEYS = {
  token: "terroir.token",
  locale: "terroir.locale",
} as const;