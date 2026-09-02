<script setup lang="ts">
/**
 * Figma component `Kbd` — 27 instances across 5 sections, always the ⌘K hint in
 * the header's global search field.
 *
 * The design sets it in Menlo at 12px on a 16px leading, in a card-surface chip
 * padded 4px across and 1px down (`230:2491`). The monospace face is the point:
 * it is what makes a key legend read as a key rather than as prose.
 */
defineProps<{ keys: string }>();
</script>

<template>
    <kbd
        class="pointer-events-none inline-flex items-center border border-border bg-card px-1 py-px font-mono text-xs leading-4 text-muted-foreground"
    >
        {{ keys }}
    </kbd>
</template>
