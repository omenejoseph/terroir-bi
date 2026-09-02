import { onBeforeUnmount, ref, watch, type Ref } from 'vue';

/**
 * The dismiss behaviour every popover surface in this app needs: close on
 * Escape, close when the pointer goes down anywhere outside, and give focus
 * back to whatever opened it.
 *
 * Written once because getting it wrong is invisible until someone uses the
 * keyboard: a dropdown that only closes when you pick something traps a
 * screen-reader user, and one that closes on `click` rather than `pointerdown`
 * swallows the first click of whatever you were reaching for next.
 *
 * `anchor` should wrap both the trigger and the panel, so a click inside the
 * panel is not treated as an outside click.
 */
export function usePopover(anchor: Ref<HTMLElement | null>) {
    const open = ref(false);

    /** The element to hand focus back to — normally the trigger. */
    let restoreTo: HTMLElement | null = null;

    function onPointerDown(event: PointerEvent): void {
        const el = anchor.value;

        if (el !== null && !el.contains(event.target as Node)) close();
    }

    function onKeydown(event: KeyboardEvent): void {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close();
        }
    }

    function show(): void {
        if (open.value) return;

        restoreTo = document.activeElement as HTMLElement | null;
        open.value = true;
    }

    function close({ restoreFocus = true }: { restoreFocus?: boolean } = {}): void {
        if (!open.value) return;

        open.value = false;

        if (restoreFocus) restoreTo?.focus();
        restoreTo = null;
    }

    function toggle(): void {
        open.value ? close() : show();
    }

    watch(open, (isOpen) => {
        if (typeof document === 'undefined') return;

        if (isOpen) {
            // `pointerdown` rather than `click`: closing on click would let the
            // dismissing press also activate whatever is underneath.
            document.addEventListener('pointerdown', onPointerDown, true);
            document.addEventListener('keydown', onKeydown, true);
        } else {
            document.removeEventListener('pointerdown', onPointerDown, true);
            document.removeEventListener('keydown', onKeydown, true);
        }
    });

    onBeforeUnmount(() => {
        document.removeEventListener('pointerdown', onPointerDown, true);
        document.removeEventListener('keydown', onKeydown, true);
    });

    return { open, show, close, toggle };
}
