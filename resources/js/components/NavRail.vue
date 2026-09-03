<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

import AppLogo from '@/components/AppLogo.vue';
import NavRow from '@/components/NavRow.vue';
import Avatar from '@/components/ui/Avatar.vue';
import { useAuth } from '@/composables/useAuth';
import { usePopover } from '@/composables/usePopover';
import { cn } from '@/lib/cn';
import { navigationFor, shortcutsFor, SHORTCUTS_ICON, type NavCategory } from '@/lib/navigation';

/**
 * The collapsed navigation (Figma `230:2399`, "NavRail") — the state every
 * designed screen frame is actually drawn in.
 *
 * Geometry from that node: a 56px rail (55px of surface plus the 1px rule) on
 * the sidebar surface, a 48px logo band, then 40px targets carrying 18px glyphs
 * stacked with a 4px gap inside 8px of padding, and a 48px footer above a top
 * rule. The active target is a full-bleed square wash — the one place the nav
 * drops the 8px radius its expanded rows carry.
 *
 * Each target stands in for a whole category, so hovering one has to open the
 * category (the design calls them `PreviewCardTrigger`); without that the rail
 * would be nine buttons that reach nothing. The flyout renders the same NavRow
 * as the expanded sidebar, so a category reads identically in both states.
 */
const { user, can, hasModule } = useAuth();

const categories = computed(() => navigationFor(can, hasModule));
const shortcuts = computed(() => shortcutsFor(can, hasModule));

/**
 * Shortcuts sit second, between Overview and the rest, exactly as the expanded
 * nav orders them. Modelling it as a pseudo-category keeps the rail one list
 * instead of a special case wedged into the middle.
 */
const groups = computed<NavCategory[]>(() => {
    const [overview, ...rest] = categories.value;
    const pinned: NavCategory[] =
        shortcuts.value.length > 0
            ? [{ label: 'Shortcuts', icon: SHORTCUTS_ICON, items: shortcuts.value }]
            : [];

    return overview === undefined ? pinned : [overview, ...pinned, ...rest];
});

const rail = ref<HTMLElement | null>(null);
const { open, show, close } = usePopover(rail);
const openLabel = ref<string | null>(null);

/**
 * Where the open flyout sits, in viewport coordinates.
 *
 * The panel has to be `fixed` rather than absolutely placed inside the rail:
 * the nav scrolls, and a scroll container clips its own overflow, so a panel
 * positioned against the trigger would be cut off at the rail's edge — which
 * is exactly what happened the first time this was built. Fixed positioning
 * takes it out of that box; the trade is that the coordinates have to be read
 * off the trigger.
 *
 * It is flush against the rail on purpose. A gap between trigger and panel is
 * a strip where the pointer is inside neither, and crossing it would close the
 * thing you are reaching for.
 */
const flyout = ref<{ top: number; left: number } | null>(null);

/**
 * Hover opens, but so must focus — the rail is nine icon buttons, and a
 * keyboard user tabbing through them needs the same labels a mouse gets.
 */
function reveal(label: string, trigger: HTMLElement): void {
    const rect = trigger.getBoundingClientRect();

    flyout.value = {
        // Keep a tall category on screen when it opens near the bottom.
        top: Math.min(rect.top, Math.max(8, window.innerHeight - 8 - estimatedHeight(label))),
        // The rail's edge, not the 40px target's — the target is inset 7.5px,
        // so anchoring to it would slide the panel back over the rail.
        left: rail.value?.getBoundingClientRect().right ?? rect.right,
    };
    openLabel.value = label;
    show();
}

/** Panel padding (16) + heading (~20) + rows (31 each), enough to clamp against. */
function estimatedHeight(label: string): number {
    const group = groups.value.find((g) => g.label === label);

    return 36 + (group?.items.length ?? 0) * 31;
}

function dismiss(): void {
    openLabel.value = null;
    flyout.value = null;
    // No focus restore: the pointer leaving is not a dismissal the keyboard
    // asked for, and yanking focus back to the rail would fight the user.
    close({ restoreFocus: false });
}

/** Only close on focusout when focus actually left the group, not on a hop within it. */
function onFocusOut(event: FocusEvent, label: string): void {
    if (openLabel.value !== label) return;

    const next = event.relatedTarget as Node | null;

    if (next !== null && (event.currentTarget as HTMLElement).contains(next)) return;

    dismiss();
}

/** A category is current when any row inside it is. */
function isCurrent(group: NavCategory, url: string): boolean {
    return group.items.some(
        (item) =>
            item.href !== null &&
            (url === item.href || url.startsWith(`${item.href}/`) || url.startsWith(`${item.href}?`)),
    );
}
</script>

<template>
    <div ref="rail" class="flex h-full w-14 flex-col items-center border-r border-sidebar-border bg-sidebar">
        <div class="flex h-12 shrink-0 items-center justify-center">
            <Link href="/dashboard" aria-label="Terroir — Business Intelligence">
                <AppLogo />
            </Link>
        </div>

        <nav class="flex min-h-0 w-10 flex-1 flex-col items-center gap-1 overflow-y-auto py-2" aria-label="Main">
            <div
                v-for="group in groups"
                :key="group.label"
                class="relative shrink-0"
                @mouseenter="reveal(group.label, $event.currentTarget as HTMLElement)"
                @mouseleave="dismiss"
                @focusin="reveal(group.label, $event.currentTarget as HTMLElement)"
                @focusout="onFocusOut($event, group.label)"
            >
                <button
                    type="button"
                    :aria-label="group.label"
                    :aria-expanded="open && openLabel === group.label"
                    aria-haspopup="menu"
                    :class="
                        cn(
                            'grid size-10 place-items-center transition-colors',
                            isCurrent(group, $page.url)
                                ? 'bg-primary text-primary-foreground'
                                : 'text-foreground hover:bg-sidebar-active',
                        )
                    "
                    @click="
                        open && openLabel === group.label
                            ? dismiss()
                            : reveal(group.label, ($event.currentTarget as HTMLElement).parentElement!)
                    "
                >
                    <component :is="group.icon" class="size-[18px]" :stroke-width="1.5" />
                </button>

                <!-- The flyout stands in for the category heading the rail has no room for. -->
                <div
                    v-if="open && openLabel === group.label && flyout"
                    role="menu"
                    :aria-label="group.label"
                    class="fixed z-40 w-56 rounded-nav border border-sidebar-border bg-sidebar p-2 shadow-lg"
                    :style="{ top: `${flyout.top}px`, left: `${flyout.left}px` }"
                >
                    <p class="px-2 pb-1 text-13 text-muted-foreground">{{ group.label }}</p>
                    <ul class="space-y-px">
                        <li v-for="item in group.items" :key="item.label">
                            <NavRow :item="item" />
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="flex h-12 w-full shrink-0 items-center justify-center border-t border-sidebar-border">
            <Link
                href="/logout"
                method="post"
                as="button"
                class="grid size-10 place-items-center"
                aria-label="Sign out"
            >
                <!-- The nav's own avatar is the round dark one (230:2472), not
                     the square muted tile the tables use. -->
                <Avatar :name="user?.name" tone="primary" />
            </Link>
        </div>
    </div>
</template>
