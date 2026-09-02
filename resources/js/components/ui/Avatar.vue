<script setup lang="ts">
import { computed } from 'vue';

import { cn } from '@/lib/cn';

/**
 * Figma components `AvatarRoot` + `AvatarFallback` — 28 instances each across 5
 * sections. A muted square with a hairline border and the person's initials in
 * the foreground colour: sampled from `453:4938`, where the fill is #f5f5f5 and
 * the border #e5e5e5, not the dark tile this used to draw.
 */
const props = withDefaults(defineProps<{ name?: string | null; size?: 'sm' | 'md'; class?: string }>(), {
    size: 'md',
});

const initials = computed(() =>
    (props.name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join(''),
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center justify-center border border-border bg-muted font-semibold text-foreground',
                props.size === 'sm' ? 'size-6 text-[10px]' : 'size-8 text-2xs',
                props.class,
            )
        "
        aria-hidden="true"
    >
        {{ initials || '—' }}
    </span>
</template>
