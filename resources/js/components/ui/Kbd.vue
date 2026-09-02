<script setup lang="ts">
/**
 * Figma component `Kbd` — 27 instances across 5 sections, always the ⌘K hint in
 * the header's global search field.
 */
defineProps<{ keys: string }>();
</script>

<template>
    <kbd
        class="pointer-events-none inline-flex h-5 items-center border border-border bg-muted px-1.5 font-sans text-2xs font-medium text-muted-foreground"
    >
        {{ keys }}
    </kbd>
</template>
