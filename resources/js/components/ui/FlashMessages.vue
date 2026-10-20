<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

import type { SharedProps } from '@/types';

/**
 * Renders the one-shot session flash shared by HandleInertiaRequests. Sits in
 * the layout so any controller can `->with('success', …)` on a redirect and
 * have it surface without touching the page component.
 *
 * It floats at the top of the viewport and clears itself, so the message is seen wherever on a long
 * page the action was taken — "Production recorded" at the foot of a tab is no use at the top of it.
 */
const page = usePage<SharedProps>();
// A delete that can be undone shows its message on the UndoToast instead.
const success = computed(() => (page.props.flash.undo ? null : page.props.flash.success));
const error = computed(() => page.props.flash.error);

const dismissed = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

// Each new message (a fresh object each response) shows again, then goes after a while. Errors stay until the next page.
watch(
    () => [success.value, error.value, page.props.flash],
    () => {
        dismissed.value = false;
        clearTimeout(timer);

        if (success.value && !error.value) timer = setTimeout(() => (dismissed.value = true), 6000);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div
        v-if="(success || error) && !dismissed"
        class="pointer-events-none fixed inset-x-0 top-4 z-50 mx-auto flex w-full max-w-md flex-col gap-2 px-4"
        aria-live="polite"
    >
        <p
            v-if="success"
            class="pointer-events-auto rounded-lg border border-success/30 bg-card px-4 py-3 text-sm text-foreground shadow-lg"
        >
            {{ success }}
        </p>
        <p
            v-if="error"
            class="pointer-events-auto rounded-lg border border-destructive/30 bg-card px-4 py-3 text-sm text-foreground shadow-lg"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>
