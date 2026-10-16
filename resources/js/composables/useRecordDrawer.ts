import { reactive } from 'vue';

/**
 * The cellar's Record drawer, opened from anywhere: the lot page's action bar,
 * the cellar tabs' Record button, or the `R` key. One drawer per app, mounted
 * in AppLayout; this is its shared state.
 */
export interface RecordTarget {
    lotId: string;
    vesselId: string | null;
}

const state = reactive<{ open: boolean; target: RecordTarget | null }>({ open: false, target: null });

export function useRecordDrawer() {
    return {
        state,
        openRecord(target: RecordTarget | null = null): void {
            state.target = target;
            state.open = true;
        },
        closeRecord(): void {
            state.open = false;
        },
    };
}
