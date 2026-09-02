<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { cn } from '@/lib/cn';
import type { TabItem } from '@/types/ui';

/**
 * Figma components `TabsRoot` / `TabsTab` — 98 tab instances across 4 sections.
 *
 * The design uses two visual treatments of the same control, so they are
 * variants here rather than two components: `underline` for a module's
 * sub-navigation (Inventory / Analytics / Spend / Check) and `segmented` for
 * in-card range pickers (Today / MTD / YTD) and the Product Detail tab strip.
 *
 * A tab with an `href` navigates; one with a `value` emits `select`. A tab with
 * neither is a designed destination that is not built yet and renders disabled,
 * matching how the sidebar treats unported modules.
 */
const props = withDefaults(defineProps<{ items: TabItem[]; current: string; variant?: 'underline' | 'segmented' | 'filter' }>(), {
    variant: 'underline',
});

const emit = defineEmits<{ select: [value: string] }>();

const isCurrent = (tab: TabItem) => tab.label === props.current || tab.value === props.current;

function tabClass(tab: TabItem): string {
    const active = isCurrent(tab);

    if (props.variant === 'segmented') {
        return cn(
            'rounded-md px-3 py-1.5 text-13 transition-colors',
            active ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
            !tab.href && !tab.value && 'cursor-not-allowed opacity-60',
        );
    }

    /*
      `filter`: standalone bordered buttons whose active state is a solid dark
      fill (Figma 389:1592's Finished / Semi-Finished / Raw Materials row) —
      distinct from `segmented`, which is a light pill on a grey track.
    */
    if (props.variant === 'filter') {
        return cn(
            'rounded-md border px-3 py-1.5 text-13 transition-colors',
            active
                ? 'border-primary bg-primary text-primary-foreground'
                : 'border-border bg-card text-foreground hover:border-foreground/40',
            !tab.href && !tab.value && 'cursor-not-allowed opacity-60',
        );
    }

    return cn(
        '-mb-px border-b-2 px-3 py-2 text-sm transition-colors',
        active
            ? 'border-foreground font-medium text-foreground'
            : 'border-transparent text-muted-foreground hover:text-foreground',
        !tab.href && !tab.value && 'cursor-not-allowed opacity-60',
    );
}
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-wrap items-center',
                variant === 'segmented'
                    ? 'gap-1 rounded-lg bg-muted p-1'
                    : variant === 'filter'
                      ? 'gap-2'
                      : 'gap-1 border-b border-border',
            )
        "
        role="tablist"
    >
        <template v-for="tab in items" :key="tab.label">
            <Link v-if="tab.href" :href="tab.href" role="tab" :aria-selected="isCurrent(tab)" :class="tabClass(tab)">
                {{ tab.label }}
            </Link>
            <button
                v-else-if="tab.value !== undefined"
                type="button"
                role="tab"
                :aria-selected="isCurrent(tab)"
                :class="tabClass(tab)"
                @click="emit('select', tab.value)"
            >
                {{ tab.label }}
            </button>
            <span v-else role="tab" aria-disabled="true" :class="tabClass(tab)">{{ tab.label }}</span>
        </template>
    </div>
</template>
