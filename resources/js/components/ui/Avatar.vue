<script setup lang="ts">
import { computed } from 'vue';

import { cn } from '@/lib/cn';

/**
 * Figma components `AvatarRoot` + `AvatarFallback`.
 *
 * The design draws two treatments and they are not interchangeable: lists and
 * tables use a square muted tile with a hairline border (`453:4938`, fill
 * #f5f5f5 on #e5e5e5), while the navigation's own avatar — the signed-in member
 * — is a round dark disc with 12px initials (`230:2472`, `547:1875`). `tone`
 * picks between them so both stay in one component rather than the nav growing
 * a private copy.
 */
const props = withDefaults(
    defineProps<{
        name?: string | null;
        size?: 'sm' | 'md';
        /** `muted` = the square list tile; `primary` = the nav's round disc. */
        tone?: 'muted' | 'primary';
        class?: string;
    }>(),
    { size: 'md', tone: 'muted' },
);

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
                'inline-flex shrink-0 items-center justify-center border font-semibold',
                props.size === 'sm' ? 'size-6 text-3xs' : 'size-8',
                props.tone === 'primary'
                    ? 'rounded-full border-border bg-primary text-xs text-primary-foreground'
                    : cn('border-border bg-muted text-foreground', props.size === 'md' && 'text-2xs'),
                props.class,
            )
        "
        aria-hidden="true"
    >
        {{ initials || '—' }}
    </span>
</template>
