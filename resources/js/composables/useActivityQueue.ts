import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';

import { csrfHeader } from '@/lib/csrf';

/**
 * Cellar › Today's ticks, made for a cellar with patchy signal. A tick shows
 * as done at once; the request goes out as JSON, and if the phone is offline
 * (or the server can't be reached) it waits in this browser's queue and is
 * sent again when the connection is back, the page is shown again, or every
 * 30 seconds. The server answers 409 for work that's already done or skipped —
 * a replay of a tick that did arrive — which counts as settled. A real
 * problem (422) puts the card back with the message on it.
 *
 * A skip or a move can be undone (the Undo toast): while the tick is still
 * waiting in the queue it is simply taken out; once the server has it, the
 * undo token it answered with puts the task back. Undo pressed while the
 * tick is on its way runs as soon as the answer arrives.
 */
export type QueuedAction = 'complete' | 'skip' | 'reschedule';

interface QueuedTick {
    activityId: string;
    action: QueuedAction;
    data: Record<string, unknown>;
    at: string;
}

const KEY = 'terroir.cellar.todayQueue';

function read(): QueuedTick[] {
    try {
        return JSON.parse(window.localStorage.getItem(KEY) ?? '[]') as QueuedTick[];
    } catch {
        return [];
    }
}

const queue = ref<QueuedTick[]>(typeof window === 'undefined' ? [] : read());
/** Ticks made on this page view: activity id → what was done (shown before the server confirms). */
const local = reactive<Record<string, QueuedAction>>({});
const errors = reactive<Record<string, string>>({});
/** Activity id → the server's undo token for its skip / move. */
const undoTokens: Record<string, string> = {};
/** Undo pressed before the server answered: activity id → resolve when done. */
const undoWhenSettled: Record<string, (ok: boolean) => void> = {};
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
let flushing = false;
/** The queued tick being sent right now (it's still first in the queue). */
let inFlight: string | null = null;
let started = false;
let reloadTimer: ReturnType<typeof setTimeout> | null = null;

function save(): void {
    try {
        window.localStorage.setItem(KEY, JSON.stringify(queue.value));
    } catch {
        // storage unavailable — the queue lasts for this page view
    }
}

type Outcome = { settled: true; undo: string | null } | 'retry' | { error: string };

async function send(tick: QueuedTick): Promise<Outcome> {
    try {
        const res = await fetch(`/cellar/activities/${tick.activityId}/${tick.action}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
            body: JSON.stringify(tick.data),
        });
        if (res.ok) {
            const body = (await res.json().catch(() => ({}))) as { data?: { undo?: string | null } };

            return { settled: true, undo: body.data?.undo ?? null };
        }
        if (res.status === 409 || res.status === 404) return { settled: true, undo: null };
        if (res.status === 422) {
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

async function flush(): Promise<void> {
    if (flushing || queue.value.length === 0) return;
    flushing = true;
    try {
        while (queue.value.length) {
            const tick = queue.value[0]!;
            inFlight = tick.activityId;
            const outcome = await send(tick);
            inFlight = null;
            if (outcome === 'retry') break;
            queue.value = queue.value.filter((q) => q !== tick);
            save();
            if ('error' in outcome) {
                delete local[tick.activityId];
                errors[tick.activityId] = outcome.error;
                undoFailed(tick.activityId);
            } else {
                settledWith(tick.activityId, outcome.undo);
            }
        }
        if (queue.value.length === 0) refreshSoon();
    } finally {
        flushing = false;
    }
}

function settledWith(activityId: string, token: string | null): void {
    if (token) undoTokens[activityId] = token;
    const waiting = undoWhenSettled[activityId];
    if (waiting) {
        delete undoWhenSettled[activityId];
        void undoSent(activityId).then(waiting);
    }
}

function undoFailed(activityId: string): void {
    undoWhenSettled[activityId]?.(false);
    delete undoWhenSettled[activityId];
}

/** Put back a tick the server already has. */
async function undoSent(activityId: string): Promise<boolean> {
    const token = undoTokens[activityId];
    if (!token) return false;
    try {
        const res = await fetch(`/cellar/undo/${token}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', ...csrfHeader() },
        });
        if (!res.ok) return false;
    } catch {
        return false; // offline: the task stays skipped / moved — the toast says so
    }
    delete undoTokens[activityId];
    delete local[activityId];
    refreshSoon();

    return true;
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
        /** Tick it now; send it now or later. */
        async submit(activityId: string, action: QueuedAction, data: Record<string, unknown> = {}): Promise<void> {
            delete errors[activityId];
            local[activityId] = action;
            const tick: QueuedTick = { activityId, action, data, at: new Date().toISOString() };
            if (queue.value.length > 0) {
                // Keep order: something is already waiting.
                queue.value = [...queue.value, tick];
                save();
                void flush();

                return;
            }
            const outcome = await send(tick);
            if (outcome !== 'retry' && 'settled' in outcome) {
                settledWith(activityId, outcome.undo);
                refreshSoon();
            } else if (outcome === 'retry') {
                if (undoWhenSettled[activityId]) {
                    // Undone before it could be sent: it never needs to go.
                    undoWhenSettled[activityId]!(true);
                    delete undoWhenSettled[activityId];
                    delete local[activityId];

                    return;
                }
                queue.value = [...queue.value, tick];
                save();
            } else {
                delete local[activityId];
                errors[activityId] = outcome.error;
                undoFailed(activityId);
            }
        },
        /** Undo a skip or move. Resolves false when it couldn't be put back. */
        undo(activityId: string): Promise<boolean> {
            if (inFlight !== activityId && queue.value.some((q) => q.activityId === activityId)) {
                // Never left this phone: drop it from the queue.
                queue.value = queue.value.filter((q) => q.activityId !== activityId);
                save();
                delete local[activityId];

                return Promise.resolve(true);
            }
            if (undoTokens[activityId]) return undoSent(activityId);
            if (local[activityId]) {
                // On its way: undo when the server answers.
                return new Promise((resolve) => (undoWhenSettled[activityId] = resolve));
            }

            return Promise.resolve(false);
        },
    };
}
