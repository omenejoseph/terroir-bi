<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';

import { cn } from '@/lib/cn';
import type { NavItem } from '@/lib/navigation';
import type { SharedProps } from '@/types';

/**
 * One navigation row (Figma `547:1594`): a 15px glyph, a 10px gap and a 14px
 * label in an 8px-radius button padded 8px across and 5px down, with a 2px
 * indicator pinned to the left edge when the row is current.
 *
 * Shared between the expanded sidebar and the collapsed rail's flyouts so a
 * category reads as the same list either way — the rail is a narrower view of
 * this nav, not a second one.
 */
const props = defineProps<{ item: NavItem }>();

const page = usePage<SharedProps>();

/**
 * Longest-prefix match, so /inventory/123 highlights Inventory without
 * /dashboard also matching every path that merely starts with a slash.
 */
const active = (): boolean => {
    const href = props.item.href;

    if (href === null) return false;

    return page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);
};
</script>

<template>
    <component
        :is="item.href ? Link : 'span'"
        v-bind="item.href ? { href: item.href } : { 'aria-disabled': 'true' }"
        :class="
            cn(
                'group relative flex w-full items-center gap-2.5 rounded-nav px-2 py-[5px] text-sm transition-colors',
                item.href === null
                    ? 'cursor-not-allowed text-muted-foreground/70'
                    : active()
                      ? 'bg-sidebar-active text-foreground'
                      : 'text-foreground hover:bg-sidebar-active',
            )
        "
    >
        <component :is="item.icon" class="size-[15px] shrink-0" :stroke-width="1.5" />
        <span class="truncate">{{ item.label }}</span>
        <span
            v-if="active()"
            class="absolute left-0 h-4 w-0.5 rounded-full bg-foreground"
            aria-hidden="true"
        />
    </component>
</template>
