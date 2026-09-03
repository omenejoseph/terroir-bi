<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Languages } from 'lucide-vue-next';

import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { TabItem } from '@/types/ui';

/**
 * Personal language override (LocaleController) — a full Inertia visit, so
 * every shared prop (translations, locale, org, …) recomputes for the new
 * locale on the very next response. Mounted in AppHeader (authenticated
 * chrome) and AuthLayout (guest screens: login, accept-invite), matching
 * where the outgoing React app's equivalent lived.
 */
const { locale, locales, localeLabels } = useTranslations();

const switching = ref(false);

const items = computed<TabItem[]>(() =>
    locales.value.map((code) => ({ label: localeLabels.value[code] ?? code, value: code })),
);

function select(value: string): void {
    if (value === locale.value || switching.value) return;

    switching.value = true;
    router.patch(
        '/locale',
        { locale: value },
        { preserveScroll: true, onFinish: () => (switching.value = false) },
    );
}
</script>

<template>
    <div class="flex items-center gap-2">
        <Languages class="size-4 shrink-0 text-muted-foreground" :stroke-width="1.5" aria-hidden="true" />
        <Tabs :items="items" :current="locale" @select="select" />
    </div>
</template>
