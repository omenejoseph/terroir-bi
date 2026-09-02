<script setup lang="ts">
/**
 * The page title row from the design: an H1 with the page's primary actions
 * pushed to the right (Figma 389:1592 "Heading 1", 208:5577).
 *
 * Every list/detail screen uses this so the title's size, weight and spacing
 * are defined once. The title is 20px semibold: measured off the 2x render of
 * `382:1592`, where the "Inventory" heading's ink box is 84.5x18.5 CSS px —
 * Figtree 600 at 20px, not the 30px this used to be.
 */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-xl font-semibold text-foreground">{{ title }}</h2>
            <p v-if="description" class="mt-1 text-xs text-muted-foreground">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
