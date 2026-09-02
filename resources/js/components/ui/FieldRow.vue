<script setup lang="ts">
import { cn } from '@/lib/cn';

/**
 * Figma component `FieldRow` — 40 instances across 5 sections. Two fields side
 * by side; in `317:468` they split 208 + 16 + 208 inside a 432px body, i.e. an
 * even two-column grid with a 16px gutter. Collapses to one column when there
 * is no room.
 */
const props = defineProps<{ class?: string }>();
</script>

<template>
    <div :class="cn('grid gap-4 sm:grid-cols-2', props.class)">
        <slot />
    </div>
</template>
