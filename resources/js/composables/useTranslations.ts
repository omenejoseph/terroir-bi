import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import type { SharedProps } from '@/types';

/**
 * UI copy, translated. Reads the merged file+DB-override catalog
 * (`page.props.translations`) that `HandleInertiaRequests` shares on every
 * full page visit — see `App\Services\Localization\TranslationService`.
 *
 * `__()` (PHP) stays the tool for backend-rendered strings (validation
 * messages, mail, notifications); `t()` is the Vue-template equivalent, and
 * both read the same `lang/*.json` + DB-override catalog. There is no plural
 * helper yet — neither this app nor the one it replaces has ever needed one;
 * add a narrowly-scoped `plural()` (via `Intl.PluralRules`) if a screen
 * genuinely needs count-sensitive Croatian copy (one/few/other).
 */
export function useTranslations() {
    const page = usePage<SharedProps>();

    const locale = computed(() => page.props.locale);
    const locales = computed(() => page.props.locales);
    const localeLabels = computed(() => page.props.localeLabels);

    /**
     * Looks up `key` in the current locale's catalog, falling back to the key
     * itself (matching TranslationService::get()'s "the key itself" fallback,
     * so an unwrapped/un-translated string still just reads as English).
     */
    function t(key: string, replace?: Record<string, string | number>): string {
        const line = page.props.translations[key] ?? key;

        return replace ? interpolate(line, replace) : line;
    }

    return { locale, locales, localeLabels, t };
}

/**
 * Mirrors TranslationService::makeReplacements() exactly, so the same
 * key + replace pair renders identically whether resolved server-side
 * (`__()`) or client-side (`t()`): `:name`, `:Name`, and `:NAME` all match.
 */
function interpolate(line: string, replace: Record<string, string | number>): string {
    const pairs: [string, string][] = [];

    for (const [key, value] of Object.entries(replace)) {
        const raw = String(value);

        pairs.push([`:${key}`, raw], [`:${ucfirst(key)}`, ucfirst(raw)], [`:${key.toUpperCase()}`, raw.toUpperCase()]);
    }

    // Longest placeholder first: a shorter one that is a literal prefix of a
    // longer one (":to" inside ":total") would otherwise get replaced first
    // and clip the longer placeholder into garbage — e.g. "Showing :from–:to
    // of :total orders" turning ":total" into "3tal" once ":to" -> "3" ran.
    // PHP's strtr() (the server-side mirror) already does this internally;
    // replaceAll() does not, so it is done explicitly here.
    pairs.sort(([a], [b]) => b.length - a.length);

    for (const [needle, raw] of pairs) {
        line = line.replaceAll(needle, raw);
    }

    return line;
}

function ucfirst(value: string): string {
    return value.length === 0 ? value : value.charAt(0).toUpperCase() + value.slice(1);
}
