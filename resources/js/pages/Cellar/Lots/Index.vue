<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import LotFormPanel from '@/components/cellar/LotFormPanel.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Select from '@/components/ui/Select.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { LOT_STATUS_LABELS, LOT_STATUS_TONES, WINE_TYPE_OPTIONS, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { Vessel, WineLotSummary } from '@/types/cellar';
import type { Paginated, SharedProps } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/** Every wine lot, bottled and blended included, filterable by status, vintage, type and text. */
const props = defineProps<{
    lots: Paginated<WineLotSummary>;
    /** Across every lot the filters match, not only this page. */
    totals: { count: number; volume: string };
    filters: { status: string | null; search: string | null; vintage: string | null; wine_type: string | null };
    vintages: string[];
    vessels: Vessel[];
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const locale = computed(() => page.props.locale);

const search = ref(props.filters.search ?? '');
const panel = ref(false);

/** Filtering, searching and paging are all server-side visits; a new filter starts again at page 1. */
function apply(patch: Partial<typeof props.filters> & { page?: number; per_page?: number } = {}): void {
    const next = { ...props.filters, search: search.value || null, per_page: props.lots.meta.per_page, ...patch };
    router.get('/cellar/lots', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
}

let timer: ReturnType<typeof setTimeout> | null = null;
function onSearch(): void {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => apply({}), 300);
}

const STATUS = computed(() => [{ value: '', label: t('All statuses') }, ...Object.entries(LOT_STATUS_LABELS).map(([value, label]) => ({ value, label: t(label) }))]);
const VINTAGES = computed(() => [{ value: '', label: t('All vintages') }, ...props.vintages.map((v) => ({ value: v, label: v }))]);
const TYPES = computed(() => [{ value: '', label: t('All types') }, ...WINE_TYPE_OPTIONS.map((o) => ({ ...o, label: t(o.label) }))]);
</script>

<template>
    <Head :title="t('Wine lots')" />
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Wine lots')" :description="t(':n lots · :l L', { n: totals.count, l: qty(totals.volume) })">
                <template #actions>
                    <Button size="sm" variant="outline" href="/cellar/bulk-analyses">{{ t('Bulk analyses') }}</Button>
                    <Button size="sm" variant="outline" href="/cellar/bulk-additions">{{ t('Bulk additions') }}</Button>
                    <Button v-if="can('cellar.manage')" size="sm" @click="panel = true"><Plus class="size-3.5" /> {{ t('Wine lot') }}</Button>
                </template>
            </PageHeader>
            <CellarTabs current="lots" />

            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-48 flex-1">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" class="pl-8" :placeholder="t('Search lot, number or grape…')" @input="onSearch" />
                </div>
                <Select :model-value="filters.status ?? ''" :options="STATUS" class="w-40" @update:model-value="apply({ status: $event || null })" />
                <Select :model-value="filters.vintage ?? ''" :options="VINTAGES" class="w-36" @update:model-value="apply({ vintage: $event || null })" />
                <Select :model-value="filters.wine_type ?? ''" :options="TYPES" class="w-36" @update:model-value="apply({ wine_type: $event || null })" />
            </div>

            <Card>
                <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Lot') }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Grape') }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Vintage') }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Status') }}</th>
                            <th class="hidden px-3 py-2 text-left font-medium md:table-cell">{{ t('Last analysis') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ t('Volume') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in lots.data" :key="l.id" class="border-t border-border hover:bg-accent/50">
                            <td class="px-3 py-2">
                                <Link :href="`/cellar/lots/${l.id}`" class="font-medium hover:underline">{{ l.name }}</Link>
                                <div class="text-[11px] text-muted-foreground">{{ l.lot_number }}</div>
                            </td>
                            <td class="px-3 py-2">{{ l.grape_variety }}</td>
                            <td class="px-3 py-2">{{ l.vintage ?? '—' }}</td>
                            <td class="px-3 py-2"><span :class="cn('px-1.5 py-0.5 text-[10px] font-medium', LOT_STATUS_TONES[l.status])">{{ t(LOT_STATUS_LABELS[l.status] ?? l.status) }}</span></td>
                            <td class="hidden px-3 py-2 text-muted-foreground md:table-cell">
                                <template v-if="l.latest_analysis">
                                    {{ new Date(l.latest_analysis.date).toLocaleDateString(locale, { day: 'numeric', month: 'short' }) }}
                                    · SO₂ {{ qty(l.latest_analysis.free_so2) }}/{{ qty(l.latest_analysis.total_so2) }}
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ qty(l.current_volume) }} <span class="text-muted-foreground">/ {{ qty(l.initial_volume) }} L</span></td>
                        </tr>
                        <tr v-if="lots.data.length === 0"><td colspan="6" class="px-3 py-10 text-center text-muted-foreground">{{ t('No wine lots match these filters.') }}</td></tr>
                    </tbody>
                </table>
                </div>
                <div v-if="lots.meta.total > 10" class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-3 py-3">
                    <p class="text-xs text-muted-foreground">{{ t('Showing :from–:to of :total', { from: (lots.meta.current_page - 1) * lots.meta.per_page + 1, to: Math.min(lots.meta.current_page * lots.meta.per_page, lots.meta.total), total: lots.meta.total }) }}</p>
                    <Pagination :meta="lots.meta" @update:page="(p) => apply({ page: p })" @update:per-page="(n) => apply({ per_page: n, page: 1 })" />
                </div>
            </Card>
        </div>
        <LotFormPanel :open="panel" :lot="null" :vessels="vessels" @close="panel = false" />
</template>
