<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';

import AppHeader from '@/components/AppHeader.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import NavRail from '@/components/NavRail.vue';
import FlashMessages from '@/components/ui/FlashMessages.vue';
import { cn } from '@/lib/cn';

defineProps<{ title: string }>();

/** The 240px rail is fixed on desktop and a drawer below lg, per the design. */
const mobileOpen = ref(false);

/*
  The header's panel button collapses the nav to the 56px rail (Figma
  `230:2479` toggling between `547:1568` and `230:2399`). The choice is the
  member's and it outlives the page, so it is remembered — an Inertia visit
  would otherwise spring the nav back open under them on every navigation.

  Read after mount rather than at setup: the value is per-browser, and touching
  localStorage during setup would break the moment this app is server-rendered.
*/
const STORAGE_KEY = 'terroir.nav.collapsed';
const collapsed = ref(false);

onMounted(() => {
    try {
        collapsed.value = window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        // Private mode, or storage disabled. Expanded is the sane default.
    }
});

/**
 * One button, two jobs, because the design draws one: below lg there is no rail
 * to collapse to — the nav is off-canvas — so the button opens the drawer
 * instead.
 */
function toggleNav(): void {
    if (!window.matchMedia('(min-width: 64rem)').matches) {
        mobileOpen.value = !mobileOpen.value;

        return;
    }

    collapsed.value = !collapsed.value;

    try {
        window.localStorage.setItem(STORAGE_KEY, collapsed.value ? '1' : '0');
    } catch {
        // Not remembering the choice is survivable; failing the click is not.
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-screen bg-background">
        <aside
            :class="
                cn(
                    'fixed inset-y-0 left-0 z-40 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                )
            "
        >
            <!-- Collapsing is a desktop affordance: off-canvas there is no rail
                 to collapse to, so the drawer always carries the full nav. The
                 wrappers do the switching so neither nav has to fight its own
                 display class. -->
            <template v-if="collapsed">
                <div class="hidden h-full lg:block"><NavRail /></div>
                <div class="h-full lg:hidden"><AppSidebar /></div>
            </template>
            <AppSidebar v-else />
        </aside>

        <!-- Scrim closes the mobile drawer. -->
        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            aria-hidden="true"
            @click="mobileOpen = false"
        />

        <div class="flex min-w-0 flex-1 flex-col">
            <AppHeader @toggle-sidebar="toggleNav" />

            <!-- overflow-x-hidden keeps wide tables from widening the page itself. -->
            <main class="min-w-0 flex-1 overflow-x-hidden px-4 py-6 lg:px-6">
                <FlashMessages class="mb-6" />
                <slot />
            </main>
        </div>
    </div>
</template>
