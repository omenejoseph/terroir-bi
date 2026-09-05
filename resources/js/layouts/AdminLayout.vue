<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';

import AdminHeader from '@/components/AdminHeader.vue';
import AdminSidebar from '@/components/AdminSidebar.vue';
import FlashMessages from '@/components/ui/FlashMessages.vue';
import { cn } from '@/lib/cn';

/**
 * The platform-admin shell — same skeleton as AppLayout.vue (fixed/sticky
 * aside + header + main), but with the admin sidebar/header and no
 * collapsed-rail variant: the admin nav is short enough that a single fixed
 * width covers it, so the toggle only ever opens/closes the mobile drawer.
 */
defineProps<{ title: string }>();

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
            <AdminSidebar />
        </aside>

        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            aria-hidden="true"
            @click="mobileOpen = false"
        />

        <div class="flex min-w-0 flex-1 flex-col">
            <AdminHeader @toggle-sidebar="mobileOpen = !mobileOpen" />

            <main class="min-w-0 flex-1 overflow-x-hidden px-4 py-6 lg:px-6">
                <FlashMessages class="mb-6" />
                <slot />
            </main>
        </div>
    </div>
</template>
