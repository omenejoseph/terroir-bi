import { reactive } from 'vue';

export interface ConfirmOptions {
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    /** 'danger' renders the confirm button destructive (red) — for anything that deletes or revokes. */
    tone?: 'default' | 'danger';
}

interface ConfirmState extends Required<Omit<ConfirmOptions, 'description'>> {
    open: boolean;
    description: string;
}

/**
 * A single, app-wide confirmation dialog — see ConfirmDialogHost.vue, mounted
 * once in app.ts, for the actual modal. Replaces window.confirm(), which
 * blocks the whole tab synchronously, can't be styled, and (per the bug this
 * was built to fix) doesn't compose with an Inertia mutation the way an
 * awaited Promise does.
 *
 * Usage: `if (!(await confirmDialog({ title: '…' }))) return;`
 */
const state = reactive<ConfirmState>({
    open: false,
    title: '',
    description: '',
    confirmLabel: '',
    cancelLabel: '',
    tone: 'default',
});

let resolver: ((value: boolean) => void) | null = null;

export function confirmDialog(options: ConfirmOptions): Promise<boolean> {
    // A second confirmation opened while one is already showing resolves the
    // first as cancelled rather than leaking its promise forever.
    resolver?.(false);

    state.title = options.title;
    state.description = options.description ?? '';
    state.confirmLabel = options.confirmLabel ?? '';
    state.cancelLabel = options.cancelLabel ?? '';
    state.tone = options.tone ?? 'default';
    state.open = true;

    return new Promise<boolean>((resolve) => {
        resolver = resolve;
    });
}

/** Internal — ConfirmDialogHost.vue only. */
export function useConfirmDialogState(): ConfirmState {
    return state;
}

/** Internal — ConfirmDialogHost.vue only. */
export function settleConfirmDialog(value: boolean): void {
    state.open = false;
    resolver?.(value);
    resolver = null;
}
