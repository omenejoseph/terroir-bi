<script setup lang="ts">
import { ref } from 'vue';

/**
 * The "Advanced" section from the design (Figma `317:468` collapsed,
 * `322:704` open).
 *
 * Collapsed it is a 58px bordered row: title, a summary of what is inside, and
 * a "Show" control. Open, the header keeps a tinted band and the fields appear
 * beneath it. The summary exists so the row still says what it hides.
 */
withDefaults(defineProps<{ title: string; summary?: string; defaultOpen?: boolean }>(), {
    defaultOpen: false,
});

const open = ref(false);
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-border">
        <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors"
            :class="open ? 'bg-muted' : 'hover:bg-muted/60'"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-foreground">{{ title }}</span>
                <span v-if="summary && !open" class="mt-0.5 block truncate text-xs text-muted-foreground">
                    {{ summary }}
                </span>
            </span>
            <span class="shrink-0 text-13 text-muted-foreground">{{ open ? 'Hide' : 'Show' }}</span>
        </button>

        <div v-if="open" class="flex flex-col gap-4 p-4">
            <slot />
        </div>
    </div>
</template>
