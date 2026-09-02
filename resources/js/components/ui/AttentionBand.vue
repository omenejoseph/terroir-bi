<script setup lang="ts">
import { cn } from '@/lib/cn';
import type { AttentionItem } from '@/types/ui';

/**
 * The "Needs attention" chip band (Figma `389:1592`).
 *
 * Each chip is a bordered rectangle with the condition and a **solid dark count
 * badge**. The selected chip takes a full-strength border rather than a fill,
 * so the row reads as filters rather than as status pills.
 */
defineProps<{ items: AttentionItem[]; active?: string | null }>();

const emit = defineEmits<{ select: [key: string | null] }>();
</script>

<template>
    <div v-if="items.length" class="flex flex-wrap items-center gap-2">
        <span class="text-13 text-muted-foreground">Needs attention</span>

        <button
            v-for="item in items"
            :key="item.key"
            type="button"
            :aria-pressed="active === item.key"
            :class="
                cn(
                    'inline-flex items-center gap-2 rounded-md border bg-card py-1.5 pr-1.5 pl-2.5 text-13 transition-colors',
                    active === item.key
                        ? 'border-foreground text-foreground'
                        : 'border-border text-foreground hover:border-foreground/40',
                )
            "
            @click="emit('select', active === item.key ? null : item.key)"
        >
            <span>{{ item.label }}</span>
            <span
                class="inline-flex min-w-5 items-center justify-center rounded-[3px] bg-primary px-1 py-0.5 text-2xs font-semibold tabular-nums text-primary-foreground"
            >
                {{ item.count }}
            </span>
        </button>
    </div>
</template>
