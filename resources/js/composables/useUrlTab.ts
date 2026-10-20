import { ref } from 'vue';
import type { Ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * A page's active tab, kept in the address (`?tab=…`) so a refresh or a shared link lands on the same tab.
 *
 * Switching tabs is a client-side Inertia replace — no request, no scroll jump — and updates Inertia's own idea
 * of the URL, so a later partial reload does not write the old address back. The default tab keeps a clean URL.
 */
export function useUrlTab<T extends string>(allowed: readonly T[], fallback: T, key = 'tab'): { tab: Ref<T>; select: (value: string) => void } {
    const wanted = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get(key);
    const tab = ref(allowed.includes(wanted as T) ? (wanted as T) : fallback) as Ref<T>;

    function select(value: string): void {
        if (!allowed.includes(value as T)) return;

        tab.value = value as T;

        const url = new URL(window.location.href);
        if (value === fallback) url.searchParams.delete(key);
        else url.searchParams.set(key, value);

        router.replace({ url: url.pathname + url.search, preserveScroll: true, preserveState: true });
    }

    return { tab, select };
}
