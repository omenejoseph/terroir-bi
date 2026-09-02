<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Menu } from 'lucide-vue-next';

import AppSidebar from '@/components/AppSidebar.vue';
import TenantSwitcher from '@/components/TenantSwitcher.vue';
import FlashMessages from '@/components/ui/FlashMessages.vue';
import { cn } from '@/lib/cn';

defineProps<{ title: string }>();

/** The 240px rail is fixed on desktop and a drawer below lg, per the design. */
const mobileOpen = ref(false);
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
            <AppSidebar />
        </aside>

        <!-- Scrim closes the mobile drawer. -->
        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            aria-hidden="true"
            @click="mobileOpen = false"
        />

        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b border-border bg-background/90 px-4 backdrop-blur lg:px-6"
            >
                <button
                    type="button"
                    class="rounded-lg p-2 hover:bg-accent lg:hidden"
                    :aria-expanded="mobileOpen"
                    aria-label="Toggle navigation"
                    @click="mobileOpen = !mobileOpen"
                >
                    <Menu class="size-4" :stroke-width="1.5" />
                </button>

                <!-- The design's header carries no title: each page renders its own
                     H1 in the content area, so repeating it here would double it. -->
                <div class="flex-1" />

                <TenantSwitcher />
            </header>

            <!-- overflow-x-hidden keeps wide tables from widening the page itself. -->
            <main class="min-w-0 flex-1 overflow-x-hidden px-4 py-6 lg:px-6">
                <FlashMessages class="mb-6" />
                <slot />
            </main>
        </div>
    </div>
</template>
