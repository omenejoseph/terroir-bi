<script setup lang="ts">
import { computed } from 'vue';

import { cn } from '@/lib/cn';

/**
 * Figma components `AvatarRoot` + `AvatarFallback` — 28 instances each across 5
 * sections. Renders the person's initials on the primary surface with a hairline
 * ring, as in the sidebar footer (`547:1568`).
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
                'inline-flex shrink-0 items-center justify-center rounded-full bg-primary font-semibold text-primary-foreground ring-1 ring-border',
                props.size === 'sm' ? 'size-6 text-[10px]' : 'size-8 text-2xs',
                props.class,
            )
        "
        aria-hidden="true"
    >
        {{ initials || '—' }}
    </span>
</template>
