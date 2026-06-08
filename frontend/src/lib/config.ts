/**
 * Runtime configuration. The frontend's only hard dependency on the backend is
 * this base URL — everything else is plain HTTP. Set NEXT_PUBLIC_API_URL in
 * .env.local (see .env.local.example).
 */
export const API_URL =
  process.env.NEXT_PUBLIC_API_URL ?? "http://localhost/api/v1";

export const APP_NAME = process.env.NEXT_PUBLIC_APP_NAME ?? "Terroir BI";

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