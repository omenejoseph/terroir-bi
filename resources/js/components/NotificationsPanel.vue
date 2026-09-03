<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Bell, Trash2 } from 'lucide-vue-next';

import { usePopover } from '@/composables/usePopover';
import { useTranslations } from '@/composables/useTranslations';
import { csrfHeader } from '@/lib/csrf';
import { relativeNotificationTime, resolveNotificationRoute } from '@/lib/notifications';
import type { SharedProps } from '@/types';
import type { NotificationItem } from '@/types/notifications';

/**
 * The header's notification bell (Figma `230:2478`, `230:2510` for the dot).
 * A scrollable feed a member can read, delete one at a time, or clear
 * entirely — backed by Web\NotificationController, JSON not Inertia, same
 * "fetch its own panel" shape as GlobalSearch.vue.
 *
 * Polls every 30s (matching the outgoing React bell's cadence) so the badge
 * stays current even while the panel is closed.
 */
const POLL_MS = 30_000;

const page = usePage<SharedProps>();
const anchor = ref<HTMLElement | null>(null);
const { open, close, toggle } = usePopover(anchor);
const { t } = useTranslations();

const items = ref<NotificationItem[]>([]);
const loading = ref(true);

const unreadCount = computed(() => items.value.filter((item) => !item.is_read).length);
const badge = computed(() => (unreadCount.value > 9 ? '9+' : String(unreadCount.value)));

async function load(): Promise<void> {
    try {
        const response = await fetch('/notifications', { headers: { Accept: 'application/json' } });

        if (response.ok) {
            const body = (await response.json()) as { data: NotificationItem[] };
            items.value = body.data;
        }
    } finally {
        loading.value = false;
    }
}

let poll: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    void load();
    poll = setInterval(() => void load(), POLL_MS);
});

onBeforeUnmount(() => clearInterval(poll));

/** Marks one read (optimistic + fire-and-forget), then navigates if it goes anywhere. */
async function select(item: NotificationItem): Promise<void> {
    close();

    if (!item.is_read) {
        item.is_read = true;
        void fetch('/notifications/read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', ...csrfHeader() },
            body: JSON.stringify({ ids: [item.id] }),
        });
    }

    const url = resolveNotificationRoute(item.type, item.data);
    if (url !== null) router.visit(url);
}

async function markAllRead(): Promise<void> {
    items.value = items.value.map((item) => ({ ...item, is_read: true }));
    await fetch('/notifications/read', { method: 'POST', headers: csrfHeader() });
}

async function remove(item: NotificationItem): Promise<void> {
    items.value = items.value.filter((i) => i.id !== item.id);
    await fetch(`/notifications/${item.id}`, { method: 'DELETE', headers: csrfHeader() });
}

async function clearAll(): Promise<void> {
    if (!confirm(t('Clear all notifications? This cannot be undone.'))) return;

    items.value = [];
    await fetch('/notifications/clear', { method: 'POST', headers: csrfHeader() });
}
</script>

<template>
    <div ref="anchor" class="relative">
        <button
            type="button"
            class="relative grid size-8 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            :aria-label="t('Notifications')"
            aria-haspopup="true"
            :aria-expanded="open"
            @click="toggle"
        >
            <Bell class="size-4" :stroke-width="1.5" />
            <!-- #636363 in the design (230:2510) — a neutral "there is
                 something here", not the destructive red. -->
            <span
                v-if="unreadCount > 0"
                class="absolute top-0.5 right-0.5 grid h-3.5 min-w-3.5 place-items-center rounded-full bg-neutral-500 px-0.5 text-[9px] font-medium leading-none text-white"
            >
                {{ badge }}
            </span>
        </button>

        <div
            v-if="open"
            class="absolute top-10 right-0 z-30 flex w-80 flex-col border border-border bg-card shadow-lg"
        >
            <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                <p class="text-xs font-medium">{{ t('Notifications') }}</p>
                <div class="flex items-center gap-3">
                    <button
                        v-if="unreadCount > 0"
                        type="button"
                        class="text-[11px] text-muted-foreground hover:text-foreground"
                        @click="markAllRead"
                    >
                        {{ t('Mark all read') }}
                    </button>
                    <button
                        v-if="items.length > 0"
                        type="button"
                        class="text-[11px] text-muted-foreground hover:text-destructive"
                        @click="clearAll"
                    >
                        {{ t('Clear all') }}
                    </button>
                </div>
            </div>

            <div class="max-h-96 overflow-y-auto">
                <p v-if="loading" class="px-3 py-4 text-xs text-muted-foreground">{{ t('Loading…') }}</p>

                <p v-else-if="items.length === 0" class="px-3 py-6 text-center text-xs text-muted-foreground">
                    {{ t('No notifications yet.') }}
                </p>

                <button
                    v-for="item in items"
                    :key="item.id"
                    type="button"
                    class="flex w-full items-start gap-2 border-b border-border px-3 py-2.5 text-left transition-colors last:border-b-0 hover:bg-muted/60"
                    @click="select(item)"
                >
                    <span
                        class="mt-1 size-1.5 shrink-0 rounded-full"
                        :class="item.is_read ? 'bg-transparent' : 'bg-neutral-500'"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1 space-y-0.5">
                        <span class="block truncate text-xs font-medium">{{ item.title }}</span>
                        <span v-if="item.body" class="block truncate text-xs text-muted-foreground">{{ item.body }}</span>
                        <span class="block text-[11px] text-muted-foreground">{{ relativeNotificationTime(item.created_at, page.props.locale) }}</span>
                    </span>
                    <span
                        role="button"
                        tabindex="-1"
                        class="shrink-0 p-1 text-muted-foreground hover:text-destructive"
                        :aria-label="t('Delete notification')"
                        @click.stop="remove(item)"
                    >
                        <Trash2 class="size-3.5" :stroke-width="1.5" />
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>
