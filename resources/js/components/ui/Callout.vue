<script setup lang="ts">
import { cn } from '@/lib/cn';

/**
 * The dashed advisory box the design uses to explain a data problem and offer
 * the fix — "Four wines have no spend or return to report … Check order → stock
 * link" (Figma `386:1673`), "No value per product … Add costs" (`382:1592`).
 *
 * It states a real condition and names the remedy, so it only renders when the
 * condition holds.
 */
defineProps<{ title: string; tone?: 'neutral' | 'warning'; class?: string }>();
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-wrap items-start justify-between gap-4 rounded-lg border border-dashed p-4',
                tone === 'warning' ? 'border-destructive/50' : 'border-border',
                $props.class,
            )
        "
    >
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium" :class="tone === 'warning' && 'text-destructive'">{{ title }}</p>
            <p v-if="$slots.default" class="mt-1 text-13 text-muted-foreground"><slot /></p>
        </div>
        <div v-if="$slots.action" class="shrink-0"><slot name="action" /></div>
    </div>
</template>
