<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

import FlashMessages from '@/components/ui/FlashMessages.vue';
import TenantSwitcher from '@/components/TenantSwitcher.vue';
import { useAuth } from '@/composables/useAuth';
import { cn } from '@/lib/cn';
import { navigationFor } from '@/lib/navigation';
import type { SharedProps } from '@/types';

defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const { user, can } = useAuth();

/*
  The nav is filtered by the capabilities the server resolved, so a CELLAR member
  never sees the finance sections. This mirrors the `can:*` middleware on the
  routes themselves — the middleware is the boundary, this is just tidiness.
*/
const navigation = navigationFor(can);

const mobileOpen = ref(false);
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-screen">
        <!-- Sidebar: dark chrome surface, pinned on desktop, drawer on mobile. -->
        <aside
            :class="
                cn(
                    'fixed inset-y-0 left-0 z-40 w-64 shrink-0 bg-sidebar text-white transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                )
            "
        >
            <div class="flex h-16 items-center px-6 text-lg font-semibold tracking-tight">Terroir</div>

            <nav class="space-y-1 px-3 pb-6" aria-label="Main">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    :class="
                        cn(
                            'block rounded-md px-3 py-2 text-sm font-medium transition-colors',
                            page.url.startsWith(item.href)
                                ? 'bg-white/15 text-white'
                                : 'text-white/70 hover:bg-white/10 hover:text-white',
                        )
                    "
                    @click="mobileOpen = false"
                >
                    {{ item.label }}
                </Link>
            </nav>
        </aside>

        <!-- Scrim closes the mobile drawer. -->
        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            aria-hidden="true"
            @click="mobileOpen = false"
        />

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-border bg-background/85 px-4 backdrop-blur lg:px-8">
                <button
                    type="button"
                    class="rounded-md p-2 hover:bg-accent lg:hidden"
                    :aria-expanded="mobileOpen"
                    aria-label="Toggle navigation"
                    @click="mobileOpen = !mobileOpen"
                >
                    <span class="block h-0.5 w-5 bg-foreground" />
                    <span class="mt-1 block h-0.5 w-5 bg-foreground" />
                    <span class="mt-1 block h-0.5 w-5 bg-foreground" />
                </button>

                <h1 class="min-w-0 flex-1 truncate text-base font-semibold">{{ title }}</h1>

                <TenantSwitcher />

                <div class="hidden text-sm text-muted-foreground sm:block">{{ user?.name }}</div>

                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="rounded-md px-3 py-2 text-sm font-medium hover:bg-accent"
                >
                    Sign out
                </Link>
            </header>

            <!-- overflow-x-hidden keeps wide tables from widening the page itself. -->
            <main class="min-w-0 flex-1 overflow-x-hidden px-4 py-6 lg:px-8">
                <FlashMessages class="mb-6" />
                <slot />
            </main>
        </div>
    </div>
</template>
