<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronsUpDown } from 'lucide-vue-next';

import { useAuth } from '@/composables/useAuth';
import { cn } from '@/lib/cn';
import { navigationFor, shortcutsFor, type NavItem } from '@/lib/navigation';
import type { SharedProps } from '@/types';

/**
 * The sidebar from the TERROIR design (Figma node 547:1568, "ExpandedNav").
 *
 * Geometry is taken from that node: 240px rail on a #fafafa surface with a 1px
 * right rule, a 56px header, 8px-padded nav body, and rows that are 223px wide
 * with 8px horizontal / 5px vertical padding and an 8px radius. The active row
 * carries a 60%-opacity neutral wash plus a 2px × 16px rounded indicator pinned
 * to its left edge.
 */
const page = usePage<SharedProps>();
const { user, can } = useAuth();

const categories = computed(() => navigationFor(can));
const shortcuts = computed(() => shortcutsFor(can));

/** Categories collapse; all start open, matching the design's default state. */
const collapsed = ref<Record<string, boolean>>({});
const shortcutsCollapsed = ref(false);

function toggle(label: string): void {
    collapsed.value[label] = !collapsed.value[label];
}

/**
 * Longest-prefix match, so /inventory/123 highlights Inventory without
 * /dashboard also matching every path that merely starts with a slash.
 */
function isActive(href: string | null): boolean {
    if (href === null) return false;

    return page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);
}

const initials = computed(() =>
    (user.value?.name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join(''),
);

/** Rows share one shape; only the active wash and the indicator differ. */
const rowClass = (item: NavItem) =>
    cn(
        'group relative flex w-full items-center gap-2.5 rounded-lg px-2 py-[5px] text-sm transition-colors',
        item.href === null
            ? 'cursor-not-allowed text-muted-foreground/70'
            : isActive(item.href)
              ? 'bg-sidebar-active text-foreground'
              : 'text-foreground hover:bg-sidebar-active',
    );
</script>

<template>
    <div class="flex h-full w-60 flex-col border-r border-sidebar-border bg-sidebar">
        <!-- Header: 56px, logo tile + wordmark -->
        <div class="flex h-14 shrink-0 items-center gap-2.5 px-3">
            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary">
                <span class="text-2xs font-semibold text-primary-foreground">T</span>
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-13 font-semibold text-foreground">Terroir</p>
                <p class="truncate text-2xs text-muted-foreground">Business Intelligence</p>
            </div>
        </div>

        <nav class="min-h-0 flex-1 overflow-y-auto p-2" aria-label="Main">
            <!-- Shortcuts -->
            <section v-if="shortcuts.length">
                <button
                    type="button"
                    class="flex w-full items-center gap-1.5 rounded-lg px-1.5 py-1 text-13 text-muted-foreground"
                    :aria-expanded="!shortcutsCollapsed"
                    @click="shortcutsCollapsed = !shortcutsCollapsed"
                >
                    <ChevronDown
                        class="size-3 shrink-0 transition-transform"
                        :class="shortcutsCollapsed && '-rotate-90'"
                    />
                    <span>Shortcuts</span>
                </button>
                <ul v-show="!shortcutsCollapsed" class="space-y-px pt-0.5">
                    <li v-for="item in shortcuts" :key="`shortcut-${item.label}`">
                        <component
                            :is="item.href ? Link : 'span'"
                            v-bind="item.href ? { href: item.href } : { 'aria-disabled': 'true' }"
                            :class="rowClass(item)"
                        >
                            <component :is="item.icon" class="size-[15px] shrink-0" :stroke-width="1.5" />
                            <span class="truncate">{{ item.label }}</span>
                        </component>
                    </li>
                </ul>
            </section>

            <div class="py-2"><div class="h-px bg-sidebar-border" /></div>

            <!-- Categories -->
            <section v-for="category in categories" :key="category.label" class="pb-3">
                <button
                    type="button"
                    class="flex w-full items-center gap-1.5 rounded-lg px-1.5 py-1 text-13 text-muted-foreground"
                    :aria-expanded="!collapsed[category.label]"
                    @click="toggle(category.label)"
                >
                    <ChevronDown
                        class="size-3 shrink-0 transition-transform"
                        :class="collapsed[category.label] && '-rotate-90'"
                    />
                    <span>{{ category.label }}</span>
                </button>

                <ul v-show="!collapsed[category.label]" class="space-y-px pt-0.5">
                    <li v-for="item in category.items" :key="item.label">
                        <component
                            :is="item.href ? Link : 'span'"
                            v-bind="item.href ? { href: item.href } : { 'aria-disabled': 'true' }"
                            :class="rowClass(item)"
                        >
                            <component :is="item.icon" class="size-[15px] shrink-0" :stroke-width="1.5" />
                            <span class="truncate">{{ item.label }}</span>
                            <!-- 2px active indicator pinned to the row's left edge -->
                            <span
                                v-if="isActive(item.href)"
                                class="absolute left-0 h-4 w-0.5 rounded-full bg-foreground"
                                aria-hidden="true"
                            />
                        </component>
                    </li>
                </ul>
            </section>
        </nav>

        <!-- Footer: avatar + identity -->
        <div class="shrink-0 border-t border-sidebar-border p-3">
            <Link
                href="/logout"
                method="post"
                as="button"
                class="flex w-full items-center gap-2.5 rounded-lg p-2 text-left transition-colors hover:bg-sidebar-active"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-2xs font-semibold text-primary-foreground ring-1 ring-sidebar-border"
                >
                    {{ initials || '—' }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-13 font-semibold text-foreground">{{ user?.name }}</span>
                    <span class="block truncate text-2xs text-muted-foreground">
                        {{ page.props.auth.roles[0] ?? 'Member' }}
                    </span>
                </span>
                <ChevronsUpDown class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" />
            </Link>
        </div>
    </div>
</template>
