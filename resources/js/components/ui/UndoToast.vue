<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Undo2, X } from 'lucide-vue-next';

import { useTranslations } from '@/composables/useTranslations';
import { useUndoToast } from '@/composables/useUndoToast';
import type { SharedProps } from '@/types';

/**
 * The toast after something that can be undone: a cellar delete (offered by
 * the server's `undo` flash) or skipping / moving a task on Today (offered by
 * the page through useUndoToast). It carries the message and an Undo button
 * for ten seconds; the server keeps the undo for ten minutes, but the moment
 * to change your mind is now. FlashMessages skips the success banner when
 * there's an undo, so the message isn't shown twice.
 */
const SECONDS = 10;

const page = usePage<SharedProps>();
const { t } = useTranslations();
const toast = useUndoToast();

const left = ref(SECONDS);
const busy = ref(false);
let timer: ReturnType<typeof setInterval> | null = null;
let lastFlash: string | null = null;

function stop(): void {
    if (timer) clearInterval(timer);
    timer = null;
}

watch(
    () => page.props.flash.undo,
    (next) => {
        if (!next || next === lastFlash) return;
        lastFlash = next;
        toast.offer(page.props.flash.success ?? t('Deleted.'), () => {
            router.post(`/cellar/undo/${next}`, {}, { preserveScroll: true });
        });
    },
    { immediate: true },
);

watch(
    () => toast.current.value?.id,
    (id) => {
        stop();
        if (id === undefined) return;
        left.value = SECONDS;
        timer = setInterval(() => {
            left.value -= 1;
            if (left.value <= 0) toast.clear(id);
        }, 1000);
    },
    { immediate: true },
);

onBeforeUnmount(stop);

async function undo(): Promise<void> {
    const offer = toast.current.value;
    if (!offer?.run || busy.value) return;
    stop();
    busy.value = true;
    try {
        await offer.run();
    } finally {
        busy.value = false;
        toast.clear(offer.id); // a no-op if run() offered something new (an error)
    }
}

const message = computed(() => toast.current.value?.message ?? '');
const progress = computed(() => `${(left.value / SECONDS) * 100}%`);
</script>

<template>
    <Transition
        enter-active-class="transition-all duration-150 ease-out"
        leave-active-class="transition-all duration-100 ease-in"
        enter-from-class="translate-y-2 opacity-0"
        leave-to-class="translate-y-2 opacity-0"
    >
        <div
            v-if="toast.current.value"
            class="fixed inset-x-4 bottom-4 z-50 mx-auto max-w-md overflow-hidden bg-foreground text-background shadow-2xl sm:inset-x-auto sm:left-1/2 sm:w-[28rem] sm:-translate-x-1/2"
            role="status"
            aria-live="polite"
            data-undo-toast
        >
            <div class="flex items-center gap-3 px-4 py-3">
                <p class="min-w-0 flex-1 text-sm">{{ message }}</p>
                <button
                    v-if="toast.current.value.run"
                    type="button"
                    class="flex h-8 shrink-0 items-center gap-1.5 bg-background/15 px-3 text-sm font-semibold hover:bg-background/25 disabled:opacity-50"
                    :disabled="busy"
                    @click="undo"
                >
                    <Undo2 class="size-4" :stroke-width="2" />
                    {{ t('Undo') }}
                    <span class="tabular-nums text-background/60">{{ left }}</span>
                </button>
                <button type="button" class="p-1 text-background/60 hover:text-background" :aria-label="t('Close')" @click="toast.clear()">
                    <X class="size-4" />
                </button>
            </div>
            <div class="h-0.5 bg-background/40 transition-[width] duration-1000 ease-linear" :style="{ width: progress }" />
        </div>
    </Transition>
</template>
