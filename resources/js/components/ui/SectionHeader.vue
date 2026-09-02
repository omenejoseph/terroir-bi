<script setup lang="ts">
/**
 * Figma component `SectionHeader` — 24 instances across 3 sections. A titled
 * band inside a card, with an optional explanatory line and trailing actions;
 * e.g. "Current stock / What is in the warehouse…" on `449:1577`.
 */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-foreground">{{ title }}</h3>
            <p v-if="description" class="mt-0.5 text-xs text-muted-foreground">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2"><slot name="actions" /></div>
    </div>
</template>
