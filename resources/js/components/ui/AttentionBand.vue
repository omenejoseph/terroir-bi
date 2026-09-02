<script setup lang="ts">
import { cn } from '@/lib/cn';
import type { AttentionItem } from '@/types/ui';

/**
 * The "Needs attention" chip band (Figma 389:1592) — one chip per condition
 * with a count.
 */
defineProps<{ items: AttentionItem[] }>();
</script>

<template>
    <div v-if="items.length" class="flex flex-wrap items-center gap-2">
        <span class="text-13 font-medium text-muted-foreground">Needs attention</span>
        <span
            v-for="item in items"
            :key="item.key"
            :class="
                cn(
                    'inline-flex items-center gap-2 rounded-lg border border-border bg-card px-2.5 py-1.5 text-13',
                )
            "
        >
            <span class="text-foreground">{{ item.label }}</span>
            <span class="rounded-md bg-muted px-1.5 py-0.5 text-2xs font-medium tabular-nums text-foreground">
                {{ item.count }}
            </span>
        </span>
    </div>
</template>
