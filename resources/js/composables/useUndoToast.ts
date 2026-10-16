import { ref } from 'vue';

/**
 * The one Undo toast (ui/UndoToast.vue, mounted in AppLayout). A page offers
 * an undo with a message and what Undo does; a newer offer replaces an older
 * one. Server deletes offer theirs through the `undo` flash; Today offers its
 * skip / move ticks directly, since those never reload the page.
 */
export interface UndoOffer {
    id: number;
    message: string;
    /** What Undo does; null shows the message alone (e.g. why an undo failed). */
    run: (() => void | Promise<void>) | null;
}

const current = ref<UndoOffer | null>(null);
let seq = 0;

export function useUndoToast() {
    return {
        current,
        offer(message: string, run: UndoOffer['run']): void {
            current.value = { id: ++seq, message, run };
        },
        clear(id?: number): void {
            if (id === undefined || current.value?.id === id) current.value = null;
        },
    };
}
