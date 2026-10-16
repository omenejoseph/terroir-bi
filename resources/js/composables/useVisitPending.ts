import { onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * True while an Inertia visit is in flight. A page uses it to dim what is about to be replaced (and
 * mark it busy), so a click on a period strip answers at once instead of leaving stale figures
 * looking final until the response lands.
 *
 * Prefetches are ignored — they are speculative and must not flash the page.
 */
export function useVisitPending() {
    const pending = ref(false);
    const stops: (() => void)[] = [];

    onMounted(() => {
        stops.push(
            router.on('start', (event) => {
                if (!event.detail.visit.prefetch) pending.value = true;
            }),
            router.on('finish', () => {
                pending.value = false;
            }),
        );
    });

    onBeforeUnmount(() => {
        stops.forEach((stop) => stop());
        pending.value = false;
    });

    return pending;
}
