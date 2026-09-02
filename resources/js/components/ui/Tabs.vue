<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { cn } from '@/lib/cn';
import type { TabItem } from '@/types/ui';

/**
 * Figma components `TabsRoot` / `TabsTab` — 98 tab instances across 4 sections.
 *
 * The design draws this control three ways, and which one it uses is
 * consistent by role:
 *
 * - `segmented` (default) — a grey track with a **white raised pill** for the
 *   active tab and grey labels for the rest. Used for page-level navigation:
 *   the module strip (Inventory / Analytics / Spend / Check) and the Product
 *   Detail tabs. There is no underline anywhere in the design.
 * - `solid` — the same track, but the active tab is a **solid dark** pill. Used
 *   for pickers *inside* a card: the exit-period strip and the movement-history
 *   filters on `449:1577`.
 * - `filter` — standalone bordered buttons with a solid dark active state and
 *   no track, as on the inventory category row (`389:1592`).
 *
 * A tab with an `href` navigates; one with a `value` emits `select`. A tab with
 * neither is a designed destination that is not built yet and renders disabled,
 * matching how the sidebar treats unported modules.
 */
/*
  `full` stretches the track across the content column. The design uses both
  widths for the same control: the Dashboard's period strip is a full-width
  1216px track (`208:5577`), while a module's sub-navigation hugs its tabs
  (`382:1592`, 380px). Hugging is the common case, so it is the default.
*/
const props = withDefaults(
    defineProps<{
        items: TabItem[];
        current: string;
        variant?: 'segmented' | 'solid' | 'filter';
        full?: boolean;
    }>(),
    { variant: 'segmented', full: false },
);

const emit = defineEmits<{ select: [value: string] }>();

const isCurrent = (tab: TabItem) => tab.label === props.current || tab.value === props.current;

function tabClass(tab: TabItem): string {
    const active = isCurrent(tab);

    const disabled = !tab.href && tab.value === undefined && 'cursor-not-allowed opacity-60';

    if (props.variant === 'filter') {
        return cn(
            'rounded-md border px-3 py-1.5 text-xs transition-colors',
            active
                ? 'border-primary bg-primary text-primary-foreground'
                : 'border-border bg-card text-foreground hover:border-foreground/40',
            disabled,
        );
    }

    /*
      Track-mounted tabs measure 24px tall inside a 4px-padded 32px track, with
      12px of horizontal padding either side of the label (analytics--382-1592,
      pill 364..516 @2x). The active tab carries no shadow — it is a flat white
      (or flat dark, for `solid`) rectangle against the grey track.
    */
    const onTrack = 'inline-flex h-6 shrink-0 items-center px-3 text-xs transition-colors';

    if (props.variant === 'solid') {
        return cn(
            onTrack,
            active ? 'bg-primary font-semibold text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
            disabled,
        );
    }

    // segmented — flat white pill on the grey track
    return cn(
        onTrack,
        active ? 'bg-background font-semibold text-foreground' : 'text-muted-foreground hover:text-foreground',
        disabled,
    );
}
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-wrap items-center',
                variant === 'filter' ? 'gap-2' : 'bg-muted p-1',  // tabs abut on the track — no gap (382:1592)
                variant !== 'filter' && (full ? 'w-full' : 'w-fit'),
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
