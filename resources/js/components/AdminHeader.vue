<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { PanelLeft, RefreshCw } from 'lucide-vue-next';

import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { useTranslations } from '@/composables/useTranslations';

/**
 * The admin header — same 48px bar as AppHeader.vue, stripped of the pieces
 * that only make sense inside a tenant (GlobalSearch, TenantSwitcher,
 * notifications, which are all tenant-scoped features/queries today).
 * LanguageSwitcher stays: locale is a user preference, not a tenant one.
 */
const { t } = useTranslations();

defineEmits<{ 'toggle-sidebar': [] }>();

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
            <LanguageSwitcher />

            <button
                type="button"
                :disabled="refreshing"
                class="grid size-8 shrink-0 place-items-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-50"
                :aria-label="t('Refresh')"
                @click="refresh"
            >
                <RefreshCw class="size-4" :class="refreshing && 'animate-spin'" :stroke-width="1.5" />
            </button>
        </div>
    </header>
</template>
