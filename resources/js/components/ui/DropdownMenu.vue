<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { MoreHorizontal } from 'lucide-vue-next';

import { usePopover } from '@/composables/usePopover';
import { cn } from '@/lib/cn';
import type { MenuItem } from '@/types/ui';

/**
 * The `⋯` menu the design hangs off a row or a panel header.
 *
 * Geometry from the customers row menu (Figma `230:3755`): a 144px panel with
 * 4px padding around 28px items, each carrying a 14px icon and a 12px label.
 * The task drawer's variant (`325:1017`) is the same thing wider, which is what
 * the `class` override is for.
 *
 * Items are declared rather than slotted so every menu in the app gets the same
 * keyboard handling and the same destructive treatment, instead of each caller
 * re-deciding what "Delete" looks like.
 */
const props = withDefaults(
    defineProps<{
        items: MenuItem[];
        label?: string;
        align?: 'left' | 'right';
        class?: string;
    }>(),
    { label: 'Actions', align: 'right' },
);

const emit = defineEmits<{ select: [key: string] }>();

const anchor = ref<HTMLElement | null>(null);
const menu = ref<HTMLElement | null>(null);
const { open, close, toggle } = usePopover(anchor, menu);

/*
  The menu is teleported to <body> and fixed to the trigger: inside a table's scroll container (every
  table here has one) an absolutely positioned menu is clipped, and the last row's menu vanished
  behind the table's edge. It opens downwards, or upwards when there is no room below, and closes if
  the page scrolls or resizes under it.
*/
const position = ref<Record<string, string>>({});
const dismiss = (): void => close();

function place(): void {
    const trigger = anchor.value;
    if (!trigger) return;

    const rect = trigger.getBoundingClientRect();
    const height = (menu.value?.offsetHeight ?? props.items.length * 28 + 8) + 8;
    const up = window.innerHeight - rect.bottom < height && rect.top > window.innerHeight - rect.bottom;

    position.value = {
        ...(up ? { bottom: `${window.innerHeight - rect.top + 4}px` } : { top: `${rect.bottom + 4}px` }),
        ...(props.align === 'right' ? { right: `${Math.max(8, window.innerWidth - rect.right)}px` } : { left: `${Math.max(8, rect.left)}px` }),
    };
}

watch(open, async (isOpen) => {
    if (!isOpen) {
        window.removeEventListener('scroll', onScroll, true);
        window.removeEventListener('resize', dismiss);

        return;
    }

    place();
    await nextTick();
    place(); // again with the menu's real height
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', dismiss);
});

/** Scrolling inside the menu itself is not the page moving away from it. */
function onScroll(event: Event): void {
    if (menu.value && event.target instanceof Node && menu.value.contains(event.target)) return;
    dismiss();
}

onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', dismiss);
});

function choose(item: MenuItem): void {
    if (item.disabled) return;

    close();
    emit('select', item.key);
}
</script>

<template>
    <div ref="anchor" class="relative">
        <button
            type="button"
            :aria-label="label"
            :aria-expanded="open"
            aria-haspopup="menu"
            class="grid size-7 place-items-center text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            @click.stop="toggle"
        >
            <slot name="trigger">
                <MoreHorizontal class="size-4" :stroke-width="1.5" />
            </slot>
        </button>

        <Teleport to="body">
        <div
            v-if="open"
            ref="menu"
            role="menu"
            :class="cn('fixed z-[60] min-w-36 border border-border bg-card p-1', props.class)"
            :style="position"
        >
            <button
                v-for="item in items"
                :key="item.key"
                type="button"
                role="menuitem"
                :disabled="item.disabled"
                class="flex h-7 w-full items-center gap-2 px-2 text-left text-xs transition-colors disabled:opacity-40"
                :class="
                    item.destructive
                        ? 'text-destructive hover:bg-destructive/10'
                        : 'text-foreground hover:bg-muted'
                "
                @click.stop="choose(item)"
            >
                <component
                    :is="item.icon"
                    v-if="item.icon"
                    class="size-3.5 shrink-0"
                    :stroke-width="1.5"
                    aria-hidden="true"
                />
                {{ item.label }}
            </button>
        </div>
        </Teleport>
    </div>
</template>
