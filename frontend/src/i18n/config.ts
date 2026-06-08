import { en, type Messages } from "@/i18n/messages/en";
import { hr } from "@/i18n/messages/hr";
import type { Locale } from "@/lib/config";

export { type Locale } from "@/lib/config";
export { SUPPORTED_LOCALES, DEFAULT_LOCALE } from "@/lib/config";

/** Catalog registry, keyed by locale. */
export const MESSAGES: Record<Locale, Messages> = { en, hr };

/** Human-readable language names for a switcher. */
export const LOCALE_LABELS: Record<Locale, string> = {
  hr: "Hrvatski",
  en: "English",
};