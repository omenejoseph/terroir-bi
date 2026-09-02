<script setup lang="ts">
import { CircleQuestionMark, Bell, PanelLeft, RefreshCw, Search } from 'lucide-vue-next';

import Kbd from '@/components/ui/Kbd.vue';
import TenantSwitcher from '@/components/TenantSwitcher.vue';

/**
 * The application header (Figma `389:1672`, and `230:2478` in context).
 *
 * Geometry from that node: a 48px bar on the card surface with a 32px collapse
 * toggle inset 16px on the left, and a right cluster of a 288px search field
 * carrying the ⌘K hint plus three 32px icon buttons, the last with a 6px unread
 * dot. The search field sits on the muted surface, not the card — it is the one
 * inset control on this bar, and that is what makes it read as one.
 */
defineEmits<{ 'toggle-sidebar': [] }>();
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-12 shrink-0 items-center gap-3 border-b border-border bg-card px-4"
    >
        <button
            type="button"
            class="grid size-8 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label="Toggle navigation"
            @click="$emit('toggle-sidebar')"
        >
            <PanelLeft class="size-4" :stroke-width="1.5" />
        </button>

        <div class="ml-auto flex items-center gap-2">
            <!--
              @todo Global search is not implemented. The field and its ⌘K hint
              are the design's (389:1679); wire it to a command palette that
              searches orders, SKUs and partners, and bind ⌘K / Ctrl+K to focus.
            -->
            <div class="relative hidden w-72 sm:block">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    :stroke-width="1.5"
                />
                <input
                    type="search"
                    placeholder="Search orders, SKUs, partners…"
                    aria-label="Search"
                    class="h-8 w-full border border-input bg-muted pr-10 pl-8 text-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
                <Kbd keys="⌘K" class="absolute top-1/2 right-2 -translate-y-1/2" />
            </div>

            <TenantSwitcher />

            <!-- @todo Help centre — no destination yet. -->
            <button
                type="button"
                class="grid size-8 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                aria-label="Help"
            >
                <CircleQuestionMark class="size-4" :stroke-width="1.5" />
            </button>

            <!-- @todo Sync/refresh — no background sync to trigger yet. -->
            <button
                type="button"
                class="grid size-8 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                aria-label="Refresh"
            >
                <RefreshCw class="size-4" :stroke-width="1.5" />
            </button>

            <!--
              @todo Notifications — App\Models\Notification and the push
              subscription endpoints exist; this needs a panel listing them and
              the dot bound to the unread count instead of being always on.
            -->
            <button
                type="button"
                class="relative grid size-8 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                aria-label="Notifications"
            >
                <Bell class="size-4" :stroke-width="1.5" />
                <!-- #636363 in the design (230:2510) — a neutral "there is
                     something here", not the destructive red. -->
                <span class="absolute top-1.5 right-1.5 size-1.5 rounded-full bg-neutral-500" aria-hidden="true" />
            </button>
        </div>
    </header>
</template>
