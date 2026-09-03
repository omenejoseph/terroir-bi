<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { CircleQuestionMark, PanelLeft, RefreshCw } from 'lucide-vue-next';

import GlobalSearch from '@/components/GlobalSearch.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import NotificationsPanel from '@/components/NotificationsPanel.vue';
import TenantSwitcher from '@/components/TenantSwitcher.vue';
import { useTranslations } from '@/composables/useTranslations';

const { t } = useTranslations();

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

/** Re-fetches the current page's own props — no full browser navigation. */
const refreshing = ref(false);

function refresh(): void {
    if (refreshing.value) return;

    refreshing.value = true;
    router.reload({ onFinish: () => (refreshing.value = false) });
}
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-12 shrink-0 items-center gap-3 border-b border-border bg-card px-4"
    >
        <button
            type="button"
            class="grid size-8 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            :aria-label="t('Toggle navigation')"
            @click="$emit('toggle-sidebar')"
        >
            <PanelLeft class="size-4" :stroke-width="1.5" />
        </button>

        <div class="ml-auto flex items-center gap-2">
            <GlobalSearch />

            <LanguageSwitcher />

            <TenantSwitcher />

            <!-- @todo Help centre — no destination yet. -->
            <button
                type="button"
                class="grid size-8 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                :aria-label="t('Help')"
            >
                <CircleQuestionMark class="size-4" :stroke-width="1.5" />
            </button>

            <button
                type="button"
                :disabled="refreshing"
                class="grid size-8 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-50"
                :aria-label="t('Refresh')"
                @click="refresh"
            >
                <RefreshCw class="size-4" :class="refreshing && 'animate-spin'" :stroke-width="1.5" />
            </button>

            <NotificationsPanel />
        </div>
    </header>
</template>
