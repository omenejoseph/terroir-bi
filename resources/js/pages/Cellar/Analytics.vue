<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import BarChart from '@/components/ui/BarChart.vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import StackedBar from '@/components/ui/StackedBar.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { LOT_STATUS_LABELS, WINE_TYPE_LABELS, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import type { CellarAnalytics, LotCostRow } from '@/types/cellar';
import type { SharedProps } from '@/types';

/**
 * Cellar › Analytics — the cellar in figures: what's in it and what's ready,
 * what needs attention (SO₂, analyses, overdue work, lot health, fermentation
 * alerts), how wine moved over the last 12 months, and where the money went
 * (finance only).
 */
const props = defineProps<{ analytics: CellarAnalytics; waterfall: LotCostRow[] | null }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const locale = computed(() => page.props.locale);
const currency = computed(() => page.props.org?.default_currency ?? 'EUR');
const k = computed(() => props.analytics.kpis);

const months = computed(() =>
    props.analytics.timeline.map((m) => ({
        label: new Date(`${m.month}-01`).toLocaleDateString(locale.value, { month: 'short' }),
        values: [m.in, m.out],
    })),
);
const vintages = computed(() => props.analytics.by_vintage.map((v) => ({ label: v.label, values: [v.volume] })));
const typeLabel = (l: string) => t(WINE_TYPE_LABELS[l as keyof typeof WINE_TYPE_LABELS] ?? l);
const FLAG_LABELS: Record<string, string> = { va_high: 'VA high', so2_low: 'Free SO₂ low', analysis_stale: 'Analysis 30+ days old', so2_stale: 'SO₂ 30+ days old' };
const LEVEL: Record<string, string> = { red: 'bg-destructive', amber: 'bg-tag-orange-foreground', green: 'bg-success' };
const maxCost = computed(() => Math.max(1, ...(props.waterfall ?? []).map((r) => r.cost.total)));
</script>

<template>
    <AppLayout :title="t('Cellar analytics')">
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Cellar analytics')" />
            <CellarTabs current="analytics" />

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard :label="t('Wine in cellar')" :value="`${qty(k.wine_in_cellar)} L`" />
                <StatCard :label="t('Ready to bottle')" :value="`${qty(k.ready_to_bottle)} L`" />
                <StatCard :label="t('Est. bottles (0.75 L)')" :value="qty(k.estimated_bottles)" />
                <StatCard v-if="can('finance.view')" :label="t('Avg cost / L')" :value="k.average_cost_per_liter === null ? '—' : formatMoney(k.average_cost_per_liter, currency)" />
                <StatCard :label="t('Overdue cellar tasks')" :value="String(k.overdue_tasks)" :hint="k.overdue_tasks === 0 ? t('All on track') : undefined" :alert="k.overdue_tasks > 0" />
                <StatCard :label="t('Needs SO₂')" :value="String(k.needs_so2)" :hint="t('30+ days since SO₂')" :alert="k.needs_so2 > 0" />
                <StatCard :label="t('Needs analysis')" :value="String(k.needs_analysis)" :hint="t('30+ days since an analysis')" :alert="k.needs_analysis > 0" />
                <StatCard :label="t('Vessel utilisation')" :value="k.vessel_utilisation === null ? '—' : `${qty(k.vessel_utilisation)}%`" :hint="t(':n empty vessels', { n: k.empty_vessels })" />
            </div>

            <Card class="p-4">
                <SectionHeader :title="t('Cellar in / out — last 12 months (L)')" />
                <BarChart class="mt-3" :points="months" :series="[t('In'), t('Out')]" :height="200" />
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card class="flex flex-col gap-3 p-4">
                    <SectionHeader :title="t('Pipeline by status')" />
                    <StackedBar :segments="analytics.pipeline.map((p) => ({ label: t(LOT_STATUS_LABELS[p.status.toUpperCase()] ?? p.status), value: p.volume, caption: t(':n lots · :l L', { n: p.count, l: qty(p.volume) }) }))" />
                    <SectionHeader :title="t('Volume by wine type')" />
                    <StackedBar :segments="analytics.by_type.map((v) => ({ label: typeLabel(v.label), value: v.volume, caption: `${qty(v.volume)} L` }))" />
                    <SectionHeader :title="t('Grape mix')" />
                    <StackedBar :segments="analytics.by_grape.map((v) => ({ label: v.label, value: v.volume, caption: `${qty(v.volume)} L` }))" />
                </Card>
                <Card class="p-4">
                    <SectionHeader :title="t('Volume by vintage (L)')" />
                    <BarChart class="mt-3" :points="vintages" :height="220" />
                </Card>
            </div>

            <Card v-if="waterfall" class="p-4">
                <SectionHeader :title="t('Most expensive lots')" />
                <div v-for="r in waterfall" :key="r.id" class="mt-2 grid grid-cols-[160px_1fr_90px] items-center gap-3 text-xs">
                    <Link :href="`/cellar/lots/${r.id}`" class="truncate hover:underline">{{ r.name }}</Link>
                    <div class="flex h-3 overflow-hidden bg-muted" :style="{ width: `${(r.cost.total / maxCost) * 100}%` }">
                        <div class="bg-tag-violet-foreground" :style="{ width: `${(r.cost.grape / Math.max(1, r.cost.total)) * 100}%` }" :title="t('Grape')" />
                        <div class="bg-tag-orange-foreground" :style="{ width: `${(r.cost.additions / Math.max(1, r.cost.total)) * 100}%` }" :title="t('Additions')" />
                        <div class="bg-tag-blue-foreground" :style="{ width: `${((r.cost.linked + r.cost.carried) / Math.max(1, r.cost.total)) * 100}%` }" :title="t('Other')" />
                    </div>
                    <span class="text-right tabular-nums">{{ formatMoney(r.cost.total, currency) }}</span>
                </div>
                <p class="mt-3 flex gap-4 text-[11px] text-muted-foreground">
                    <span><span class="mr-1 inline-block size-2 bg-tag-violet-foreground" />{{ t('Grape') }}</span>
                    <span><span class="mr-1 inline-block size-2 bg-tag-orange-foreground" />{{ t('Additions') }}</span>
                    <span><span class="mr-1 inline-block size-2 bg-tag-blue-foreground" />{{ t('Other') }}</span>
                </p>
            </Card>

            <Card class="overflow-x-auto">
                <div class="p-4 pb-2"><SectionHeader :title="t('Lot health')" /></div>
                <table class="w-full text-xs">
                    <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="w-6 px-3 py-2" />
                            <th class="px-2 py-2 text-left font-medium">{{ t('Lot') }}</th>
                            <th class="px-2 py-2 text-right font-medium">VA</th>
                            <th class="px-2 py-2 text-right font-medium">{{ t('Free SO₂') }}</th>
                            <th class="px-2 py-2 text-right font-medium">{{ t('Analysis age') }}</th>
                            <th class="px-2 py-2 text-left font-medium">{{ t('Attention') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="h in analytics.health" :key="h.wine_lot_id" class="border-t border-border">
                            <td class="px-3 py-1.5"><span :class="cn('inline-block size-2.5 rounded-full', LEVEL[h.level])" /></td>
                            <td class="px-2 py-1.5"><Link :href="`/cellar/lots/${h.wine_lot_id}`" class="font-medium hover:underline">{{ h.name }}</Link></td>
                            <td :class="cn('px-2 py-1.5 text-right tabular-nums', h.flags.includes('va_high') && 'text-destructive')">{{ h.volatile_acidity ?? '—' }}</td>
                            <td :class="cn('px-2 py-1.5 text-right tabular-nums', h.flags.includes('so2_low') && 'text-destructive')">{{ h.free_so2 ?? '—' }}</td>
                            <td class="px-2 py-1.5 text-right tabular-nums">{{ h.analysis_age_days === null ? '—' : t(':nd', { n: h.analysis_age_days }) }}</td>
                            <td class="px-2 py-1.5 text-muted-foreground">{{ h.flags.map((f) => t(FLAG_LABELS[f] ?? f)).join(' · ') || '—' }}</td>
                        </tr>
                        <tr v-if="analytics.health.length === 0"><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">{{ t('No lots in the cellar.') }}</td></tr>
                    </tbody>
                </table>
            </Card>

            <Card v-if="analytics.alerts.length" class="p-4 text-xs">
                <SectionHeader :title="t('Fermentation alerts')" />
                <p v-for="(a, i) in analytics.alerts" :key="i" class="mt-2"><Link :href="`/cellar/lots/${a.lot_id}`" class="font-medium hover:underline">{{ a.lot_name }}</Link> — {{ a.message }} <span class="text-muted-foreground">{{ a.suggestion }}</span></p>
            </Card>
        </div>
    </AppLayout>
</template>
