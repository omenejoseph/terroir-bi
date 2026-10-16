<script setup lang="ts">
import { computed } from 'vue';

import { PenLine } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import Kbd from '@/components/ui/Kbd.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useRecordDrawer } from '@/composables/useRecordDrawer';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import type { TabItem } from '@/types/ui';

/**
 * The cellar module's sub-navigation. The prototype had a wrapping row of
 * buttons on the cellar home and a back arrow on every sub-page; one strip on
 * every page replaces both. A tab whose screen is not built yet renders
 * disabled (Tabs' `href: null` convention).
 */
const props = defineProps<{ current: string }>();

const { t } = useTranslations();
const { can } = useAuth();
const { openRecord } = useRecordDrawer();

const BUILT = new Set(['overview', 'today', 'plan', 'so2', 'lots', 'blend', 'tastings', 'products', 'protocols', 'analytics', 'costs', 'book']);

const items = computed<TabItem[]>(() =>
    [
        { key: 'overview', label: t('Cellar'), href: '/cellar' },
        { key: 'today', label: t('Today'), href: '/cellar/today' },
        { key: 'plan', label: t('Plan'), href: '/cellar/plan' },
        { key: 'so2', label: t('SO₂'), href: '/cellar/so2' },
        { key: 'lots', label: t('Lots'), href: '/cellar/lots' },
        { key: 'blend', label: t('Blend'), href: '/cellar/blend' },
        { key: 'tastings', label: t('Tastings'), href: '/cellar/tastings' },
        { key: 'products', label: t('Products'), href: '/cellar/products' },
        { key: 'protocols', label: t('Protocols'), href: '/cellar/protocols' },
        { key: 'analytics', label: t('Analytics'), href: '/cellar/analytics' },
        { key: 'costs', label: t('Costs'), href: '/cellar/costs', finance: true },
        { key: 'book', label: t('Cellar book'), href: '/cellar/book' },
    ]
        .filter((tab) => !tab.finance || can('finance.view'))
        .map((tab) => ({ label: tab.label, href: BUILT.has(tab.key) ? tab.href : null, key: tab.key })),
);

const currentLabel = computed(() => items.value.find((i) => (i as TabItem & { key: string }).key === props.current)?.label ?? '');
</script>

<template>
    <div class="flex items-center gap-2 print:hidden">
        <div class="-mx-1 min-w-0 flex-1 overflow-x-auto px-1"><Tabs :items="items" :current="currentLabel" /></div>
        <Button v-if="can('cellar.manage')" size="sm" variant="outline" class="shrink-0" :title="t('Record cellar work (R)')" @click="openRecord()">
            <PenLine class="size-3.5" /> {{ t('Record') }} <Kbd keys="R" class="hidden sm:inline-flex" />
        </Button>
    </div>
</template>
