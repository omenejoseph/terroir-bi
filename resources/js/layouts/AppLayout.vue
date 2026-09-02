<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';

import AppHeader from '@/components/AppHeader.vue';
import AppSidebar from '@/components/AppSidebar.vue';
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
            <AppHeader @toggle-sidebar="mobileOpen = !mobileOpen" />

            <!-- overflow-x-hidden keeps wide tables from widening the page itself. -->
            <main class="min-w-0 flex-1 overflow-x-hidden px-4 py-6 lg:px-6">
                <FlashMessages class="mb-6" />
                <slot />
            </main>
        </div>
    </div>
</template>
