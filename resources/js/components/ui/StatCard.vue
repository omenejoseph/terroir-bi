<script setup lang="ts">
import { cn } from '@/lib/cn';

/**
 * A headline figure with a caption.
 *
 * `alert` reproduces the design's warning treatment (Figma `386:1673`): the
 * value turns red and a small red square appears top-right. It marks a figure
 * that is *true but wrong* — capital sitting idle, a return rate far below
 * what the stock value implies — so it should only be set from a real
 * condition, never to draw the eye.
 */
withDefaults(defineProps<{ label: string; value: string; hint?: string; alert?: boolean }>(), {
    alert: false,
});
</script>

<template>
    <div class="rounded-lg border border-border bg-card p-5">
        <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-medium text-muted-foreground">{{ label }}</p>
            <span v-if="alert" class="mt-1 size-2 shrink-0 rounded-[1px] bg-destructive" aria-hidden="true" />
        </div>
        <p :class="cn('mt-2 text-2xl font-semibold tracking-tight tabular-nums', alert && 'text-destructive')">
            {{ value }}
        </p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
