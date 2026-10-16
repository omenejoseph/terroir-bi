<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import type { SharedProps } from '@/types';

/**
 * Renders the one-shot session flash shared by HandleInertiaRequests. Sits in
 * the layout so any controller can `->with('success', …)` on a redirect and
 * have it surface without touching the page component.
 */
const page = usePage<SharedProps>();
// A delete that can be undone shows its message on the UndoToast instead.
const success = computed(() => (page.props.flash.undo ? null : page.props.flash.success));
</script>

<template>
    <div v-if="success || page.props.flash.error" class="space-y-2" aria-live="polite">
        <p
            v-if="success"
            class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-foreground"
        >
            {{ success }}
        </p>
        <p
            v-if="page.props.flash.error"
            class="rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-foreground"
            role="alert"
        >
            {{ page.props.flash.error }}
        </p>
    </div>
</template>
