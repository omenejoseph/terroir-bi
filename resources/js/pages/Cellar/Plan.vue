<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import PlanBuilder from '@/components/cellar/PlanBuilder.vue';
import PlanList from '@/components/cellar/PlanList.vue';
import VesselMapPicker from '@/components/cellar/VesselMapPicker.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { useUrlTab } from '@/composables/useUrlTab';
import type { CellarActivityType, CellarPlan, CellarPreselect, LotOption, MemberOption, ParcelOption, ProductOption, Vessel } from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Cellar › Plan — plan work for the cellar team (builder + map stage), and see
 * what is planned and what was recently done. Everything planned here shows
 * up on Cellar › Today.
 */
const props = defineProps<{
    plan: CellarPlan;
    lots: LotOption[];
    vessels: Vessel[];
    products: ProductOption[];
    members: MemberOption[];
    parcels: ParcelOption[];
    preselect: CellarPreselect;
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can, user } = useAuth();

const canManage = computed(() => can('cellar.manage'));
const locale = computed(() => page.props.locale);
const errors = computed(() => Object.values(page.props.errors ?? {}));

// The map's action bar opens this page with `?vessels=…&type=…`: start in whole-cellar mode with them ticked.
const type = ref<CellarActivityType>((props.preselect.type as CellarActivityType | null) ?? 'ADDITION');
const scope = ref<'lot' | 'cellar'>(props.preselect.vessels.length ? 'cellar' : 'lot');
const selected = ref<string[]>([...props.preselect.vessels]);

const showMap = computed(() => scope.value === 'cellar' && !['TRANSFER', 'VINEYARD'].includes(type.value));
const mappedVessels = computed(() => props.vessels.filter((v) => v.position_x !== null && v.position_y !== null));
const selectableOnMap = computed(() => mappedVessels.value.filter((v) => (v.lots ?? []).some((l) => l.lot !== null)));

const placeholder = computed(() => {
    if (type.value === 'TRANSFER') return t('Transfers pick source and destination in the left column.');
    if (type.value === 'VINEYARD') return t('Vineyard work targets parcels — pick them in the left column.');

    return t('One-lot mode selects the lot and its vessels in the left column. Switch to Whole cellar to pick on the map.');
});

const openCount = computed(() => props.plan.open.reduce((n, d) => n + d.singles.length + d.rounds.reduce((m, r) => m + r.items.length, 0), 0));
const { tab: drawer, select: selectDrawer } = useUrlTab(['open', 'executed'] as const, 'open');
const drawerTabs = computed<TabItem[]>(() => [
    { label: t('Open plan · :n', { n: openCount.value }), value: 'open' },
    { label: t('Recently executed'), value: 'executed' },
]);

const vesselNames = computed(() => new Map(props.vessels.map((v) => [v.id, v.name])));
const vesselName = (id: string) => vesselNames.value.get(id);
</script>

<template>
    <Head :title="t('Plan work')" />
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Plan work')" :description="t('Plan cellar work — the team sees it on Today and confirms it at the tank.')" />
            <CellarTabs current="plan" />

            <div v-if="errors.length" class="border border-destructive/40 bg-tag-red px-3 py-2 text-sm text-tag-red-foreground" role="alert">
                <p v-for="(e, i) in errors" :key="i">{{ e }}</p>
            </div>

            <div v-if="canManage" class="grid border border-border bg-card lg:min-h-[640px] lg:grid-cols-[420px_1fr]">
                <div class="border-b border-border lg:max-h-[calc(100vh-180px)] lg:border-r lg:border-b-0">
                    <PlanBuilder
                        v-model:type="type"
                        v-model:scope="scope"
                        v-model:selected="selected"
                        :today="plan.today"
                        :me-id="user?.id ?? null"
                        :locale="locale"
                        :lots="lots"
                        :vessels="vessels"
                        :products="products"
                        :members="members"
                        :parcels="parcels"
                    />
                </div>
                <div class="min-w-0 p-4">
                    <template v-if="showMap && mappedVessels.length">
                        <div class="mb-3 flex items-center gap-3 text-sm">
                            <span class="font-medium">{{ t('Vessels · :sel/:total', { sel: selected.length, total: selectableOnMap.length }) }}</span>
                            <button type="button" class="text-xs underline underline-offset-2" @click="selected = selectableOnMap.map((v) => v.id)">{{ t('Select all') }}</button>
                            <button type="button" class="text-xs underline underline-offset-2" @click="selected = []">{{ t('Clear') }}</button>
                        </div>
                        <VesselMapPicker v-model="selected" :vessels="mappedVessels" />
                    </template>
                    <div v-else-if="showMap" class="flex h-full min-h-40 items-center justify-center border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                        {{ t('No vessels are placed on the cellar map yet — place them on the map to pick them here.') }}
                    </div>
                    <div v-else class="flex h-full min-h-40 items-center justify-center border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                        {{ placeholder }}
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <Tabs :items="drawerTabs" :current="drawer" @select="selectDrawer" />
                <PlanList
                    :days="drawer === 'open' ? plan.open : plan.executed"
                    :mode="drawer"
                    :locale="locale"
                    :can-manage="canManage"
                    :vessel-name="vesselName"
                />
            </div>
        </div>
</template>
