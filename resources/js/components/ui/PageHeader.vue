<script setup lang="ts">
/**
 * The page title row from the design: an H1 with the page's primary actions
 * pushed to the right (Figma 389:1592 "Heading 1", 208:5577).
 *
 * Every list/detail screen uses this so the title's size, weight and spacing
 * are defined once.
 */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-3xl font-semibold tracking-tight text-foreground">{{ title }}</h2>
            <p v-if="description" class="mt-1 text-13 text-muted-foreground">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
