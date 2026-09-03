<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronsUpDown, Settings2 } from 'lucide-vue-next';

import AppLogo from '@/components/AppLogo.vue';
import ManageShortcutsDialog from '@/components/ManageShortcutsDialog.vue';
import NavRow from '@/components/NavRow.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Separator from '@/components/ui/Separator.vue';
import { useAuth } from '@/composables/useAuth';
import { navigationFor, shortcutsFor } from '@/lib/navigation';
import type { SharedProps } from '@/types';

/**
 * The expanded sidebar (Figma node 547:1568, "ExpandedNav").
 *
 * Geometry is taken from that node: 240px rail on a #fafafa surface with a 1px
 * right rule, a 56px header, 8px-padded nav body, and rows that are 223px wide
 * with 8px horizontal / 5px vertical padding and an 8px radius (see NavRow).
 *
 * Type is the design's too, and it is not the ramp the content area uses: the
 * wordmark and the footer's name are 13px on a 16.25px leading, their
 * subtitles 11px on 13.75px, and the category headings 13px on 19.5px — only
 * the nav rows themselves are 14px.
 *
 * The collapsed counterpart is NavRail; AppLayout picks between them.
 */
const page = usePage<SharedProps>();
const { user, can, hasModule, shortcuts: pinnedShortcuts } = useAuth();

const allCategories = computed(() => navigationFor(can, hasModule));
const shortcuts = computed(() => shortcutsFor(can, hasModule, pinnedShortcuts.value));

/*
  The design orders the rail: Overview, rule, Shortcuts, rule, then everything
  else (Figma 547:1568). Splitting Overview out keeps that order explicit rather
  than depending on where it happens to sit in NAV_CATEGORIES.
*/
const overview = computed(() => allCategories.value.filter((c) => c.label === 'Overview'));
const categories = computed(() => allCategories.value.filter((c) => c.label !== 'Overview'));

/** "ADMIN" -> "Admin": the design shows the role in sentence case. */
const roleLabel = computed(() => {
    const role = page.props.auth.roles[0];

    return role ? role.charAt(0) + role.slice(1).toLowerCase() : 'Member';
});

/** Categories collapse; all start open, matching the design's default state. */
const collapsed = ref<Record<string, boolean>>({});
const shortcutsCollapsed = ref(false);
const manageShortcutsOpen = ref(false);

function toggle(label: string): void {
    collapsed.value[label] = !collapsed.value[label];
}
</script>

<template>
    <div class="flex h-full w-60 flex-col border-r border-sidebar-border bg-sidebar">
        <!-- Header: 56px, logo tile + wordmark -->
        <div class="flex h-14 shrink-0 items-center gap-2.5 px-3">
            <AppLogo />
            <div class="min-w-0 flex-1">
                <p class="truncate text-13 leading-[16.25px] font-semibold text-foreground">Terroir</p>
                <p class="truncate text-2xs text-muted-foreground">Business Intelligence</p>
            </div>
        </div>

        <nav class="min-h-0 flex-1 overflow-y-auto p-2" aria-label="Main">
            <!-- Overview -->
            <section v-for="category in overview" :key="category.label">
                <button
                    type="button"
                    class="flex w-full items-center gap-1.5 rounded-nav px-1.5 py-1 text-13 text-muted-foreground"
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
                        <NavRow :item="item" />
                    </li>
                </ul>
            </section>

            <div v-if="overview.length" class="py-2">
                <Separator class="bg-sidebar-border" />
            </div>

            <!--
              Shortcuts (Figma 547:1610): stays visible even with nothing
              pinned yet — its "manage" trigger is the only way to pin a
              first one.
            -->
            <section>
                <div class="flex items-center gap-1.5">
                    <button
                        type="button"
                        class="flex flex-1 items-center gap-1.5 rounded-nav px-1.5 py-1 text-13 text-muted-foreground"
                        :aria-expanded="!shortcutsCollapsed"
                        @click="shortcutsCollapsed = !shortcutsCollapsed"
                    >
                        <ChevronDown
                            class="size-3 shrink-0 transition-transform"
                            :class="shortcutsCollapsed && '-rotate-90'"
                        />
                        <span>Shortcuts</span>
                    </button>
                    <button
                        type="button"
                        class="mr-1.5 grid size-5 shrink-0 place-items-center text-muted-foreground transition-colors hover:text-foreground"
                        aria-label="Manage shortcuts"
                        @click="manageShortcutsOpen = true"
                    >
                        <Settings2 class="size-3" :stroke-width="1.5" />
                    </button>
                </div>
                <ul v-if="shortcuts.length" v-show="!shortcutsCollapsed" class="space-y-px pt-0.5">
                    <li v-for="item in shortcuts" :key="`shortcut-${item.label}`">
                        <NavRow :item="item" />
                    </li>
                </ul>
                <p v-else-if="!shortcutsCollapsed" class="px-1.5 pt-0.5 text-xs text-muted-foreground">
                    Nothing pinned yet.
                </p>
            </section>

            <div class="py-2"><Separator class="bg-sidebar-border" /></div>

            <!-- Categories -->
            <section v-for="category in categories" :key="category.label" class="pb-3">
                <button
                    type="button"
                    class="flex w-full items-center gap-1.5 rounded-nav px-1.5 py-1 text-13 text-muted-foreground"
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
                        <NavRow :item="item" />
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
                class="flex w-full items-center gap-2.5 rounded-nav p-2 text-left transition-colors hover:bg-sidebar-active"
            >
                <Avatar :name="user?.name" tone="primary" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-13 leading-[16.25px] font-semibold text-foreground">
                        {{ user?.name }}
                    </span>
                    <span class="block truncate text-2xs text-muted-foreground">{{ roleLabel }}</span>
                </span>
                <ChevronsUpDown class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" />
            </Link>
        </div>

        <ManageShortcutsDialog
            :open="manageShortcutsOpen"
            :pinned-keys="pinnedShortcuts"
            @close="manageShortcutsOpen = false"
        />
    </div>
</template>
