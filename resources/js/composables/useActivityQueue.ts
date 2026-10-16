import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';

import { csrfHeader } from '@/lib/csrf';
import type { CellarActivity } from '@/types/cellar';
import { useUndoToast } from '@/composables/useUndoToast';

/**
 * Cellar › Today's ticks, made for a cellar with patchy signal. A tick shows
 * as done at once; the request goes out as JSON, and if the phone is offline
 * (or the server can't be reached) it waits in this browser's queue and is
 * sent again when the connection is back, the page is shown again, or every
 * 30 seconds. The server answers 409 for work that's already done or skipped —
 * a replay of a tick that did arrive — which counts as settled. A real
 * problem (422) puts the card back with the message on it.
 *
 * A skip or a move can be undone (the Undo toast). While the tick is still
 * waiting in the queue it is simply taken out. Once the server has it, the
 * undo goes into the same queue, carrying the undo token the server answered
 * with, and is retried like any tick until the server answers; a retry of an
 * undo that already went through is answered as done. Undo pressed while the
 * tick is on its way is queued as soon as the answer arrives. If the server
 * finally says no (too late, or the task was done meanwhile), the toast says
 * why with the server's own message.
 *
 * A queued undo carries a copy of the task as it was (and its room), so Today
 * can show it back in the open list straight away — the server only lists it
 * again once the undo reaches it.
 */
export type QueuedAction = 'complete' | 'skip' | 'reschedule';

interface QueuedItem {
    activityId: string;
    action: QueuedAction | 'undo';
    /** The tick's fields; for an undo, `{ token, task }`. */
    data: Record<string, unknown>;
    at: string;
}

const KEY = 'terroir.cellar.todayQueue';

function read(): QueuedItem[] {
    try {
        return JSON.parse(window.localStorage.getItem(KEY) ?? '[]') as QueuedItem[];
    } catch {
        return [];
    }
}

const queue = ref<QueuedItem[]>(typeof window === 'undefined' ? [] : read());
/** Ticks made on this page view: activity id → what was done (shown before the server confirms). */
const local = reactive<Record<string, QueuedAction>>({});
const errors = reactive<Record<string, string>>({});
/** Activity id → the server's undo token for its skip / move. */
const undoTokens: Record<string, string> = {};
/** Undo pressed while the tick was on its way: queue the undo once it lands. */
const undoWhenSettled = new Map<string, RestoredTask>();

/** Undone on the server, but Today's data hasn't been reloaded since. */
const justRestored = ref<RestoredTask[]>([]);

/** A task as it was before its skip / move, to show while its undo is on the way. */
export interface RestoredTask {
    room: string;
    activity: CellarActivity;
}
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
let flushing = false;
/** The queued item being sent right now (it's still in the queue). */
let inFlight: QueuedItem | null = null;
let started = false;
let reloadTimer: ReturnType<typeof setTimeout> | null = null;

function save(): void {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(queue.value));
    } catch {
        // storage unavailable — the queue lasts for this page view
    }
}

function enqueue(item: QueuedItem): void {
    queue.value = [...queue.value, item];
    save();
}

type Outcome = { settled: true; undo: string | null } | 'retry' | { error: string };

async function send(item: QueuedItem): Promise<Outcome> {
    const url = item.action === 'undo' ? `/cellar/undo/${String(item.data.token)}` : `/cellar/activities/${item.activityId}/${item.action}`;
    try {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
            body: item.action === 'undo' ? '{}' : JSON.stringify(item.data),
        });
        if (res.ok) {
            const body = (await res.json().catch(() => ({}))) as { data?: { undo?: string | null } };

            return { settled: true, undo: body.data?.undo ?? null };
        }
        if (res.status === 409 || res.status === 404) return { settled: true, undo: null };
        if (res.status === 422 || res.status === 410) {
            const body = (await res.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };

            return { error: Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? '' }; // '' → the card shows its own translated fallback
        }

        return 'retry'; // 419 (expired session), 5xx: try again later
    } catch {
        return 'retry'; // offline / unreachable
    }
}

/** Refresh Today's numbers once things settle (only when we can reach the server). */
function refreshSoon(): void {
    if (reloadTimer) clearTimeout(reloadTimer);
    reloadTimer = setTimeout(() => {
        if (queue.value.length === 0) router.reload({ only: ['today'] });
    }, 800);
}

/** What the server said once an item is settled for good. */
function settle(item: QueuedItem, outcome: Exclude<Outcome, 'retry'>): void {
    if ('error' in outcome) {
        undoWhenSettled.delete(item.activityId);
        if (item.action === 'undo') {
            // The task stays skipped / moved; say why where it was undone.
            useUndoToast().offer(outcome.error, null);
        } else {
            delete local[item.activityId];
            errors[item.activityId] = outcome.error;
        }

        return;
    }
    if (item.action === 'undo') {
        // Through: keep showing it until Today's data is reloaded and lists it again.
        if (item.data.task) justRestored.value = [...justRestored.value, item.data.task as RestoredTask];

        return;
    }
    if (outcome.undo) undoTokens[item.activityId] = outcome.undo;
    const restored = undoWhenSettled.get(item.activityId);
    if (restored) {
        undoWhenSettled.delete(item.activityId);
        queueUndo(item.activityId, restored);
    }
}

/** Put back a tick the server already has: through the queue, like any tick. */
function queueUndo(activityId: string, task: RestoredTask): boolean {
    const token = undoTokens[activityId];
    if (!token) return false;
    delete undoTokens[activityId];
    delete local[activityId];
    enqueue({ activityId, action: 'undo', data: { token, task }, at: new Date().toISOString() });
    void flush();

    return true;
}

async function flush(): Promise<void> {
    if (flushing || queue.value.length === 0) return;
    flushing = true;
    try {
        while (queue.value.length) {
            const item = queue.value[0]!;
            inFlight = item;
            const outcome = await send(item);
            inFlight = null;
            if (outcome === 'retry') break;
            queue.value = queue.value.filter((q) => q !== item);
            save();
            settle(item, outcome);
        }
        if (queue.value.length === 0) refreshSoon();
    } finally {
        inFlight = null;
        flushing = false;
    }
}

function start(): void {
    if (started || typeof window === 'undefined') return;
    started = true;
    window.addEventListener('online', () => {
        online.value = true;
        void flush();
    });
    window.addEventListener('offline', () => (online.value = false));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') void flush();
    });
    setInterval(() => void flush(), 30_000);
    void flush();
}

export function useActivityQueue() {
    start();

    return {
        local,
        errors,
        online,
        pending: computed(() => queue.value.length),
        isQueued: (activityId: string) => queue.value.some((q) => q.activityId === activityId),
        undoPending: (activityId: string) => queue.value.some((q) => q.activityId === activityId && q.action === 'undo'),
        /** Tasks whose undo is waiting to reach the server — shown as open again meanwhile. */
        restoring: computed<RestoredTask[]>(() => [
            ...queue.value.filter((q) => q.action === 'undo' && q.data.task).map((q) => q.data.task as RestoredTask),
            ...justRestored.value,
        ]),
        /** Today's data was reloaded: it lists restored tasks itself now. */
        forgetRestored(): void {
            justRestored.value = [];
        },
        /** Tick it now; send it now or later. */
        async submit(activityId: string, action: QueuedAction, data: Record<string, unknown> = {}): Promise<void> {
            delete errors[activityId];
            local[activityId] = action;
            const item: QueuedItem = { activityId, action, data, at: new Date().toISOString() };
            if (queue.value.length > 0) {
                // Keep order: something is already waiting.
                enqueue(item);
                void flush();

                return;
            }
            const outcome = await send(item);
            if (outcome === 'retry') {
                if (undoWhenSettled.delete(activityId)) {
                    // Undone before it could be sent: it never needs to go.
                    delete local[activityId];

                    return;
                }
                enqueue(item);

                return;
            }
            settle(item, outcome);
            if ('settled' in outcome) refreshSoon();
        },
        /**
         * Undo a skip or move. False only when there's nothing this phone can
         * undo (the server never gave it a token); otherwise the undo is done
         * or on its way.
         */
        undo(activityId: string, task: RestoredTask): boolean {
            const waiting = queue.value.find((q) => q.activityId === activityId && q.action !== 'undo');
            if (waiting && waiting !== inFlight) {
                // Never left this phone: drop it from the queue.
                queue.value = queue.value.filter((q) => q !== waiting);
                save();
                delete local[activityId];

                return true;
            }
            if (undoTokens[activityId]) return queueUndo(activityId, task);
            if (local[activityId]) {
                // On its way: queue the undo when the server answers.
                undoWhenSettled.set(activityId, task);
                delete local[activityId];

                return true;
            }

            return false;
        },
    };
}
