<script setup lang="ts">
import { cn } from '@/lib/cn';

/**
 * Figma component `Separator` — 142 instances across all 6 sections, making it
 * the design's second most-used primitive after Button. It was being inlined as
 * `<div class="h-px bg-border" />` in several places; this is that, once.
 */
const props = withDefaults(defineProps<{ orientation?: 'horizontal' | 'vertical'; class?: string }>(), {
    orientation: 'horizontal',
});
</script>

<template>
    <div
        role="separator"
        :aria-orientation="props.orientation"
        :class="cn(props.orientation === 'vertical' ? 'w-px self-stretch' : 'h-px w-full', 'bg-border', props.class)"
    />
</template>
