import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/**
 * The live content width of an element, so SVG charts can draw at the width
 * they are shown at (real-size text and strokes) instead of scaling a fixed
 * viewBox. `fallback` is used until the first measurement.
 */
export function useElementWidth(target: Ref<HTMLElement | null>, fallback = 720) {
    const width = ref(fallback);
    let observer: ResizeObserver | null = null;

    onMounted(() => {
        const el = target.value;
        if (!el) return;

        width.value = Math.round(el.clientWidth) || fallback;

        if (typeof ResizeObserver === 'undefined') return;

        observer = new ResizeObserver(([entry]) => {
            const next = Math.round(entry?.contentRect.width ?? 0);
            if (next > 0) width.value = next;
        });
        observer.observe(el);
    });

    onBeforeUnmount(() => observer?.disconnect());

    return width;
}
