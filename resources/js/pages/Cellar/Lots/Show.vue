<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Cylinder, Droplets, FlaskConical, Grape, GlassWater, Pencil, Trash2, Wine, Wrench, ArrowRightLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import HistoryList from '@/components/cellar/HistoryList.vue';
import LotFormPanel from '@/components/cellar/LotFormPanel.vue';
import AdditionPanel from '@/components/cellar/lot/AdditionPanel.vue';
import AnalysisPanel from '@/components/cellar/lot/AnalysisPanel.vue';
import BottlingPanel from '@/components/cellar/lot/BottlingPanel.vue';
import ProcessPanel from '@/components/cellar/lot/ProcessPanel.vue';
import ProtocolCard from '@/components/cellar/lot/ProtocolCard.vue';
import TastingPanel from '@/components/cellar/lot/TastingPanel.vue';
import TransferPanel from '@/components/cellar/lot/TransferPanel.vue';
import VolumePanel from '@/components/cellar/lot/VolumePanel.vue';
import AreaChart from '@/components/ui/AreaChart.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Select from '@/components/ui/Select.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS, LOT_STATUS_LABELS, LOT_STATUS_TONES, WINE_TYPE_LABELS, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import type {
    AnalysisParam,
    AnalysisRow,
    FermentationTemplate,
    HistoryEntry,
    LotOption,
    ProductOption,
    TimelineEvent,
    TraceNode,
    Vessel,
    WineLotDetail,
} from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { MenuItem, TabItem } from '@/types/ui';

/**
 * A wine lot: where it is and how much, what was done to it, what it tastes
 * and measures like, what it cost, and where it came from. `?vessel=` focuses
 * the page on one vessel (from a map click): lists filter to it and new
 * records default to it.
 */
const props = defineProps<{
    lot: WineLotDetail;
    timeline: TimelineEvent[];
    trend: AnalysisRow[];
    blendSources: { lot_id: string; name: string; lot_number: string; liters: string }[];
    vessels: Vessel[];
    lots: LotOption[];
    products: ProductOption[];
    templates: FermentationTemplate[];
    history?: HistoryEntry[];
    trace?: TraceNode;
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const canManage = computed(() => can('cellar.manage'));
const finance = computed(() => can('finance.view'));
const locale = computed(() => page.props.locale);
const currency = computed(() => page.props.org?.default_currency ?? 'EUR');
const money = (minor: number | null | undefined) => (minor === null || minor === undefined ? '—' : formatMoney(minor, currency.value));
const date = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

// ── Vessel focus ────────────────────────────────────────────────────────────
const focusId = ref<string | null>(new URLSearchParams(window.location.search).get('vessel'));
const focus = computed(() => props.lot.vessels.find((v) => v.vessel_id === focusId.value) ?? null);
function clearFocus(): void {
    focusId.value = null;
    window.history.replaceState({}, '', `/cellar/lots/${props.lot.id}`);
}
const inFocus = <T extends { vessel_id?: string | null }>(rows: T[]) => (focusId.value ? rows.filter((r) => r.vessel_id === focusId.value) : rows);

// ── Tabs ────────────────────────────────────────────────────────────────────
type TabKey = 'overview' | 'additions' | 'analysis' | 'tasting' | 'transfers' | 'processes' | 'bottling' | 'history' | 'details';
const tab = ref<TabKey>(focusId.value ? 'analysis' : 'overview');
const tabs = computed<TabItem[]>(() => [
    { label: t('Overview'), value: 'overview' },
    { label: `${t('Additions')} (${inFocus(props.lot.additions).length})`, value: 'additions' },
    { label: `${t('Analysis')} (${inFocus(props.lot.analyses).length})`, value: 'analysis' },
    { label: `${t('Tasting')} (${props.lot.tasting_notes.length})`, value: 'tasting' },
    { label: `${t('Transfers')} (${props.lot.transfers.length})`, value: 'transfers' },
    { label: `${t('Processes')} (${inFocus(props.lot.processes).length})`, value: 'processes' },
    { label: `${t('Bottling')} (${props.lot.bottlings.length})`, value: 'bottling' },
    { label: t('History'), value: 'history' },
    { label: t('Details'), value: 'details' },
]);
watch(tab, (k) => {
    if (k === 'history' && props.history === undefined) router.reload({ only: ['history'] });
    if (k === 'details' && props.trace === undefined) router.reload({ only: ['trace'] });
});

// ── Panels ──────────────────────────────────────────────────────────────────
type Panel = 'edit' | 'analysis' | 'addition' | 'transfer' | 'process' | 'tasting' | 'bottling' | 'adjust' | 'assign' | 'blend-in' | null;
const panel = ref<Panel>(null);
const editing = ref<{ analysis: AnalysisRow | null; addition: WineLotDetail['additions'][number] | null; tasting: WineLotDetail['tasting_notes'][number] | null }>({ analysis: null, addition: null, tasting: null });
function open(p: Exclude<Panel, null>, record: Partial<typeof editing.value> = {}): void {
    editing.value = { analysis: null, addition: null, tasting: null, ...record };
    panel.value = p;
}

const bottled = computed(() => props.lot.status === 'BOTTLED');
/** A bottled lot is read-only — only reversing its bottling stays available. */
const canEdit = computed(() => canManage.value && !bottled.value);

async function remove(kind: string, url: string, title: string): Promise<void> {
    if (!(await confirmDialog({ title, confirmLabel: t('Delete'), tone: 'danger' }))) return;
    router.delete(url, { preserveScroll: true });
    void kind;
}

async function deleteLot(): Promise<void> {
    const ok = await confirmDialog({
        title: t('Delete :name?', { name: props.lot.name }),
        description: t('Its vessels are freed, bottlings are reversed and all its records are removed. This cannot be undone.'),
        confirmLabel: t('Delete lot'),
        tone: 'danger',
    });
    if (ok) router.delete(`/cellar/lots/${props.lot.id}`);
}

// ── Overview figures ────────────────────────────────────────────────────────
const fill = computed(() => {
    if (focus.value) {
        const v = props.vessels.find((x) => x.id === focus.value!.vessel_id);
        const cap = Number(v?.capacity_liters ?? 0);

        return { now: Number(focus.value.volume), of: cap, pct: cap > 0 ? (Number(focus.value.volume) / cap) * 100 : 0, label: t('of :n L capacity', { n: qty(cap) }) };
    }
    const init = Number(props.lot.initial_volume);

    return { now: Number(props.lot.current_volume), of: init, pct: init > 0 ? (Number(props.lot.current_volume) / init) * 100 : 0, label: t('of :n L initial', { n: qty(init) }) };
});

const grapeTotals = computed(() => ({
    kg: props.lot.grapes.reduce((n, g) => n + Number(g.weight_kg ?? 0), 0),
    cost: props.lot.grapes.reduce((n, g) => n + (g.price_per_kg && g.weight_kg ? Math.round(g.price_per_kg.minor * Number(g.weight_kg)) : 0), 0),
}));
const latest = computed(() => inFocus(props.lot.analyses)[0] ?? null);
const SO2_KEYS: AnalysisParam[] = ['free_so2', 'total_so2'];
const latestParams = computed(() => ANALYSIS_FIELDS.filter((f) => !SO2_KEYS.includes(f.key) && latest.value?.[f.key] !== null && latest.value?.[f.key] !== undefined));

// ── Trend chart ─────────────────────────────────────────────────────────────
const trendParam = ref<AnalysisParam>('free_so2');
const trendVessel = ref(focusId.value ?? '');
const trendParams = computed(() => ANALYSIS_FIELDS.filter((f) => props.trend.some((r) => r[f.key] !== null && r[f.key] !== undefined)));
const trendVessels = computed(() => [{ value: '', label: t('All vessels (averaged)') }, ...props.lot.vessels.map((v) => ({ value: v.vessel_id, label: v.vessel_name ?? '—' }))]);
const trendPoints = computed(() => {
    const rows = props.trend.filter((r) => r[trendParam.value] !== null && r[trendParam.value] !== undefined && (trendVessel.value === '' || r.vessel_id === trendVessel.value));
    const byDay = new Map<string, number[]>();
    for (const r of [...rows].sort((a, b) => a.date.localeCompare(b.date))) {
        const day = r.date.slice(0, 10);
        byDay.set(day, [...(byDay.get(day) ?? []), Number(r[trendParam.value])]);
    }

    return [...byDay.entries()].map(([d, vals]) => ({ label: new Date(d).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' }), value: Math.round((vals.reduce((s, v) => s + v, 0) / vals.length) * 100) / 100 }));
});

const rowMenu: MenuItem[] = [
    { key: 'edit', label: 'Edit', icon: Pencil },
    { key: 'delete', label: 'Delete', icon: Trash2, destructive: true },
];
const menu = computed(() => rowMenu.map((m) => ({ ...m, label: t(m.label) })));

const vesselName = (id: string | null) => props.lot.vessels.find((v) => v.vessel_id === id)?.vessel_name ?? props.vessels.find((v) => v.id === id)?.name ?? null;

const TIMELINE_ICONS: Record<string, unknown> = { vessel: Cylinder, addition: Droplets, analysis: FlaskConical, tasting: Wine, transfer: ArrowRightLeft, transfer_in: ArrowRightLeft, process: Wrench, bottling: GlassWater, created: Grape };
</script>

<template>
    <AppLayout :title="lot.name">
        <div class="flex flex-col gap-5">
            <Link href="/cellar" class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft class="size-3.5" /> {{ t('Cellar') }}</Link>
            <PageHeader :title="lot.name" :description="[lot.lot_number, lot.grape_variety, lot.vintage, lot.vineyard].filter(Boolean).join(' · ')">
                <template #actions>
                    <span :class="cn('px-2 py-0.5 text-[11px] font-medium', LOT_STATUS_TONES[lot.status])">{{ t(LOT_STATUS_LABELS[lot.status] ?? lot.status) }}</span>
                    <span v-if="lot.wine_type" class="bg-muted px-2 py-0.5 text-[11px]">{{ t(WINE_TYPE_LABELS[lot.wine_type]) }}</span>
                    <Button v-if="canManage" size="sm" variant="outline" @click="open('edit')"><Pencil class="size-3.5" /> {{ t('Edit') }}</Button>
                    <Button v-if="can('cellar.delete')" size="sm" variant="ghost" :aria-label="t('Delete lot')" @click="deleteLot"><Trash2 class="size-3.5" /></Button>
                </template>
            </PageHeader>
            <CellarTabs current="lots" />

            <div v-if="focus" class="flex flex-wrap items-center gap-2 border border-border bg-muted/50 px-3 py-2 text-sm">
                {{ t('Viewing vessel :name — :n L', { name: focus.vessel_name ?? '', n: qty(focus.volume) }) }}
                <button type="button" class="text-xs underline underline-offset-2" @click="clearFocus">{{ t('Show all →') }}</button>
            </div>

            <div v-if="blendSources.length" class="border border-tag-violet-foreground/30 bg-tag-violet px-3 py-2 text-xs text-tag-violet-foreground">
                <p class="font-medium">{{ t('Blend sources · :n', { n: blendSources.length }) }}</p>
                <p class="mt-1 flex flex-wrap gap-x-3">
                    <Link v-for="s in blendSources" :key="s.lot_id" :href="`/cellar/lots/${s.lot_id}`" class="underline-offset-2 hover:underline">
                        {{ s.name }} · {{ qty(s.liters) }} L ({{ Math.round((Number(s.liters) / blendSources.reduce((a, b) => a + Number(b.liters), 0)) * 100) }}%)
                    </Link>
                </p>
            </div>

            <ProtocolCard v-if="lot.status === 'FERMENTING' || lot.fermentation_template_id" :lot="lot" :templates="templates" :can-manage="canManage" />

            <div v-if="canManage && !bottled" class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" @click="open('addition')"><Droplets class="size-3.5" /> {{ t('Addition') }}</Button>
                <Button size="sm" variant="outline" @click="open('analysis')"><FlaskConical class="size-3.5" /> {{ t('Analysis') }}</Button>
                <Button size="sm" variant="outline" @click="open('transfer')"><ArrowRightLeft class="size-3.5" /> {{ t('Move wine') }}</Button>
                <Button size="sm" variant="outline" @click="open('process')"><Wrench class="size-3.5" /> {{ t('Process') }}</Button>
                <Button size="sm" variant="outline" @click="open('tasting')"><Wine class="size-3.5" /> {{ t('Tasting') }}</Button>
                <Button size="sm" variant="outline" @click="open('bottling')"><GlassWater class="size-3.5" /> {{ t('Bottle') }}</Button>
            </div>

            <div class="-mx-1 overflow-x-auto px-1"><Tabs :items="tabs" :current="tab" @select="tab = $event as TabKey" /></div>

            <!-- OVERVIEW -->
            <div v-if="tab === 'overview'" class="grid gap-4 lg:grid-cols-2">
                <Card class="flex flex-col gap-3 p-4">
                    <SectionHeader :title="t('Volume')" />
                    <div>
                        <p class="text-3xl font-semibold tabular-nums">{{ qty(fill.now) }} L</p>
                        <p class="text-xs text-muted-foreground">{{ fill.label }} · {{ Math.round(fill.pct) }}%</p>
                    </div>
                    <ProgressBar :value="fill.pct" />
                    <div class="flex flex-col">
                        <Link
                            v-for="v in lot.vessels"
                            :key="v.vessel_lot_id"
                            :href="`/cellar/vessels/${v.vessel_id}`"
                            :class="cn('flex justify-between border-b border-border py-1.5 text-xs last:border-0 hover:bg-accent', v.vessel_id === focusId && 'font-semibold')"
                        >
                            <span>{{ v.vessel_name }}</span><span class="tabular-nums">{{ qty(v.volume) }} L</span>
                        </Link>
                        <p v-if="lot.vessels.length === 0" class="text-xs text-muted-foreground">{{ t('Not in a vessel.') }}</p>
                    </div>
                    <div v-if="canManage && !bottled" class="flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" @click="open('adjust')">{{ t('Adjust volume') }}</Button>
                        <Button size="sm" variant="outline" @click="open('assign')">{{ t('Assign vessel') }}</Button>
                        <Button size="sm" variant="outline" @click="open('blend-in')">{{ t('Blend in') }}</Button>
                    </div>
                </Card>

                <Card v-if="finance" class="flex flex-col gap-2 p-4 text-xs">
                    <SectionHeader :title="t('Cost breakdown')" />
                    <template v-if="lot.cost_breakdown && lot.cost_breakdown.total > 0">
                        <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Grape price') }}</span><span>{{ lot.grape_price_per_kg ? `${money(lot.grape_price_per_kg.minor)}/kg` : '—' }}</span></div>
                        <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Harvest weight') }}</span><span>{{ lot.harvest_weight_kg ? `${qty(lot.harvest_weight_kg)} kg` : '—' }}</span></div>
                        <div v-if="lot.harvest_weight_kg && Number(lot.harvest_weight_kg) > 0" class="flex justify-between">
                            <span class="text-muted-foreground">{{ t('Yield') }}</span><span>{{ qty((Number(lot.initial_volume) / Number(lot.harvest_weight_kg)) * 100) }} L / 100 kg</span>
                        </div>
                        <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Grape cost') }}</span><span>{{ money(lot.cost_breakdown.grape) }}</span></div>
                        <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Additions') }}</span><span>{{ money(lot.cost_breakdown.additions) }}</span></div>
                        <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Linked costs') }}</span><span>{{ money(lot.cost_breakdown.linked) }}</span></div>
                        <div v-if="lot.cost_breakdown.carried" class="flex justify-between"><span class="text-muted-foreground">{{ t('Carried in by blends') }}</span><span>{{ money(lot.cost_breakdown.carried) }}</span></div>
                        <div class="flex justify-between border-t border-border pt-2 font-semibold"><span>{{ t('Total') }}</span><span>{{ money(lot.cost_breakdown.total) }}</span></div>
                        <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Per litre') }}</span><span>{{ money(lot.cost_breakdown.per_liter) }}</span></div>
                        <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Per 750 ml bottle') }}</span><span>{{ money(lot.cost_breakdown.per_bottle_750) }}</span></div>
                    </template>
                    <p v-else class="text-muted-foreground">{{ t('No cost data yet.') }}</p>
                </Card>

                <Card v-if="lot.grapes.length > 1 || (finance && lot.grapes.some((g) => g.price_per_kg))" class="p-4 text-xs">
                    <SectionHeader :title="t('Grapes')" />
                    <table class="mt-2 w-full">
                        <thead class="text-[10px] uppercase text-muted-foreground">
                            <tr>
                                <th class="py-1 text-left font-medium">{{ t('Variety') }}</th>
                                <th class="py-1 text-right font-medium">%</th>
                                <th class="py-1 text-right font-medium">kg</th>
                                <th v-if="finance" class="py-1 text-right font-medium">{{ t('Price / kg') }}</th>
                                <th v-if="finance" class="py-1 text-right font-medium">{{ t('Cost') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="g in lot.grapes" :key="g.id" class="border-t border-border">
                                <td class="py-1">{{ g.grape_variety }}</td>
                                <td class="py-1 text-right tabular-nums">{{ qty(g.percentage) }}</td>
                                <td class="py-1 text-right tabular-nums">{{ qty(g.weight_kg) }}</td>
                                <td v-if="finance" class="py-1 text-right tabular-nums">{{ g.price_per_kg ? money(g.price_per_kg.minor) : '—' }}</td>
                                <td v-if="finance" class="py-1 text-right tabular-nums">{{ g.price_per_kg && g.weight_kg ? money(Math.round(g.price_per_kg.minor * Number(g.weight_kg))) : '—' }}</td>
                            </tr>
                            <tr class="border-t border-border font-medium">
                                <td class="py-1">{{ t('Total') }}</td>
                                <td />
                                <td class="py-1 text-right tabular-nums">{{ qty(grapeTotals.kg) }}</td>
                                <td v-if="finance" />
                                <td v-if="finance" class="py-1 text-right tabular-nums">{{ money(grapeTotals.cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </Card>

                <Card class="p-4 text-xs">
                    <SectionHeader :title="t('Latest analysis')" />
                    <template v-if="latest">
                        <p class="mb-2 text-muted-foreground">{{ date(latest.date) }}<template v-if="latest.vessel_name"> · {{ latest.vessel_name }}</template></p>
                        <div class="mb-3 grid grid-cols-2 gap-2">
                            <div class="bg-muted px-3 py-2"><p class="text-[10px] uppercase text-muted-foreground">{{ t('Free SO₂') }}</p><p class="text-lg font-semibold">{{ qty(latest.free_so2) }}</p></div>
                            <div class="bg-muted px-3 py-2"><p class="text-[10px] uppercase text-muted-foreground">{{ t('Total SO₂') }}</p><p class="text-lg font-semibold">{{ qty(latest.total_so2) }}</p></div>
                        </div>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                            <div v-for="f in latestParams" :key="f.key" class="flex justify-between"><span class="text-muted-foreground">{{ t(f.label) }}</span><span class="tabular-nums">{{ qty(latest[f.key]) }}</span></div>
                        </div>
                    </template>
                    <p v-else class="text-muted-foreground">{{ t('No analysis yet.') }}</p>
                </Card>

                <Card class="p-4 text-xs lg:col-span-2">
                    <SectionHeader :title="t('Lot journey')" />
                    <ol class="mt-2 flex flex-col">
                        <li v-for="(e, i) in timeline" :key="i" class="flex gap-3 border-l border-border py-1.5 pl-3">
                            <component :is="TIMELINE_ICONS[e.type] ?? Wrench" class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ e.title }}</p>
                                <p v-if="e.detail" class="truncate text-muted-foreground">{{ e.detail }}</p>
                            </div>
                            <span class="shrink-0 text-muted-foreground">{{ date(e.date) }}</span>
                        </li>
                    </ol>
                </Card>
            </div>

            <!-- ADDITIONS -->
            <Card v-else-if="tab === 'additions'" class="overflow-x-auto">
                <div v-if="lot.planned_additions.length" class="m-3 border border-dashed border-border p-3 text-xs">
                    <p class="mb-1 font-medium">{{ t('Planned — waiting for the cellar team · :n', { n: lot.planned_additions.length }) }}</p>
                    <p v-for="p in lot.planned_additions" :key="p.id" class="text-muted-foreground">
                        {{ p.planned_for }} · {{ p.product_name }} {{ qty(p.quantity) }} {{ p.unit }}<template v-if="p.vessel"> → {{ p.vessel.name }}</template>
                    </p>
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Addition') }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Vessel') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ t('Quantity') }}</th>
                            <th v-if="finance" class="px-3 py-2 text-right font-medium">{{ t('Cost') }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Date') }}</th>
                            <th class="w-10" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in inFocus(lot.additions)" :key="a.id" class="border-t border-border">
                            <td class="px-3 py-2"><p class="font-medium">{{ a.name }}</p><p v-if="a.note" class="text-muted-foreground">{{ a.note }}</p></td>
                            <td class="px-3 py-2">{{ a.vessel?.name ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ qty(a.quantity) }} {{ a.unit }}</td>
                            <td v-if="finance" class="px-3 py-2 text-right tabular-nums">{{ a.total_cost ? money(a.total_cost.minor) : '—' }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ date(a.date) }}</td>
                            <td class="px-2">
                                <DropdownMenu v-if="canEdit" :items="menu" @select="(k) => (k === 'edit' ? open('addition', { addition: a }) : remove('addition', `/cellar/lots/${lot.id}/additions/${a.id}`, t('Delete this addition? Its stock is put back.')))" />
                            </td>
                        </tr>
                        <tr v-if="inFocus(lot.additions).length === 0"><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">{{ t('No additions recorded yet.') }}</td></tr>
                    </tbody>
                </table>
            </Card>

            <!-- ANALYSIS -->
            <div v-else-if="tab === 'analysis'" class="flex flex-col gap-4">
                <Card v-if="trend.length" class="p-4">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <SectionHeader :title="t('Trend')" />
                        <Select v-model="trendParam" :options="trendParams.map((f) => ({ value: f.key, label: t(f.label) }))" class="ml-auto w-48" />
                        <Select v-model="trendVessel" :options="trendVessels" class="w-48" />
                    </div>
                    <AreaChart v-if="trendPoints.length > 1" :points="trendPoints" :height="180" />
                    <p v-else class="py-6 text-center text-xs text-muted-foreground">{{ t('Two or more readings are needed for a trend.') }}</p>
                </Card>
                <Card class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">{{ t('Date') }}</th>
                                <th class="px-3 py-2 text-left font-medium">{{ t('Vessel') }}</th>
                                <th class="px-3 py-2 text-left font-medium">{{ t('Readings') }}</th>
                                <th class="w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in inFocus(lot.analyses)" :key="a.id" class="border-t border-border">
                                <td class="whitespace-nowrap px-3 py-2">{{ date(a.date) }}</td>
                                <td class="px-3 py-2">{{ a.vessel_name ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <span v-for="f in ANALYSIS_FIELDS.filter((x) => a[x.key] !== null && a[x.key] !== undefined)" :key="f.key" class="mr-3 inline-block">
                                        <span class="text-muted-foreground">{{ t(f.label) }}</span> {{ qty(a[f.key]) }}
                                    </span>
                                    <p v-if="a.note" class="text-muted-foreground">{{ a.note }}</p>
                                </td>
                                <td class="px-2">
                                    <DropdownMenu v-if="canEdit" :items="menu" @select="(k) => (k === 'edit' ? open('analysis', { analysis: a }) : remove('analysis', `/cellar/lots/${lot.id}/analyses/${a.id}`, t('Delete this analysis?')))" />
                                </td>
                            </tr>
                            <tr v-if="inFocus(lot.analyses).length === 0"><td colspan="4" class="px-3 py-8 text-center text-muted-foreground">{{ t('No analyses yet.') }}</td></tr>
                        </tbody>
                    </table>
                </Card>
            </div>

            <!-- TASTING -->
            <div v-else-if="tab === 'tasting'" class="flex flex-col gap-2">
                <Card v-for="n in lot.tasting_notes" :key="n.id" class="flex gap-4 p-4 text-xs">
                    <div class="w-14 shrink-0 text-center"><p class="text-2xl font-semibold">{{ n.score ?? '—' }}</p><p class="text-muted-foreground">/100</p></div>
                    <div class="min-w-0 flex-1">
                        <p class="text-muted-foreground">{{ date(n.date) }}<template v-if="vesselName(n.vessel_id)"> · {{ vesselName(n.vessel_id) }}</template></p>
                        <p v-if="n.appearance"><span class="text-muted-foreground">{{ t('Appearance') }}:</span> {{ n.appearance }}</p>
                        <p v-if="n.nose"><span class="text-muted-foreground">{{ t('Nose') }}:</span> {{ n.nose }}</p>
                        <p v-if="n.palate"><span class="text-muted-foreground">{{ t('Palate') }}:</span> {{ n.palate }}</p>
                        <p v-if="n.overall"><span class="text-muted-foreground">{{ t('Overall') }}:</span> {{ n.overall }}</p>
                        <p v-if="n.note" class="text-muted-foreground">{{ n.note }}</p>
                    </div>
                    <DropdownMenu v-if="canEdit" :items="menu" @select="(k) => (k === 'edit' ? open('tasting', { tasting: n }) : remove('tasting', `/cellar/lots/${lot.id}/tasting-notes/${n.id}`, t('Delete this tasting note?')))" />
                </Card>
                <p v-if="lot.tasting_notes.length === 0" class="border border-dashed border-border p-8 text-center text-xs text-muted-foreground">{{ t('No tasting notes yet.') }}</p>
            </div>

            <!-- TRANSFERS -->
            <Card v-else-if="tab === 'transfers'">
                <div v-for="tr in lot.transfers" :key="tr.id" class="flex items-center gap-3 border-b border-border px-3 py-2 text-xs last:border-0">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">
                            <template v-if="tr.from_lot_id === tr.to_lot_id">{{ t('Racked') }} {{ tr.from_vessel_name }} → {{ tr.to_vessel_name }}</template>
                            <template v-else-if="tr.direction === 'in'">{{ t('From :lot', { lot: tr.from_lot_name ?? '—' }) }}</template>
                            <template v-else>{{ t('To :lot', { lot: tr.to_lot_name ?? '—' }) }}</template>
                        </p>
                        <p class="text-muted-foreground">{{ tr.type }} · {{ date(tr.date) }}<template v-if="tr.note"> · {{ tr.note }}</template></p>
                    </div>
                    <span :class="cn('tabular-nums font-semibold', tr.from_lot_id === tr.to_lot_id ? '' : tr.direction === 'in' ? 'text-success' : 'text-destructive')">
                        {{ tr.from_lot_id === tr.to_lot_id ? '' : tr.direction === 'in' ? '+' : '−' }}{{ qty(tr.volume_liters) }} L
                    </span>
                    <button
                        v-if="canEdit"
                        type="button"
                        class="p-1 text-muted-foreground hover:text-destructive"
                        :aria-label="t('Reverse')"
                        @click="remove('transfer', `/cellar/lots/${lot.id}/transfers/${tr.id}`, t('Reverse this transfer? The wine goes back where it came from.'))"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </div>
                <p v-if="lot.transfers.length === 0" class="p-8 text-center text-xs text-muted-foreground">{{ t('No transfers yet.') }}</p>
            </Card>

            <!-- PROCESSES -->
            <Card v-else-if="tab === 'processes'" class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Process') }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Vessel') }}</th>
                            <th class="px-3 py-2 text-right font-medium">L</th>
                            <th class="px-3 py-2 text-left font-medium">{{ t('Date') }}</th>
                            <th class="w-10" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in inFocus(lot.processes)" :key="p.id" class="border-t border-border">
                            <td class="px-3 py-2"><p class="font-medium">{{ p.kind }}</p><p v-if="p.note" class="text-muted-foreground">{{ p.note }}</p></td>
                            <td class="px-3 py-2">{{ p.vessel_name ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ qty(p.volume) }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ date(p.date) }}</td>
                            <td class="px-2">
                                <button v-if="canEdit" type="button" class="p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Delete')" @click="remove('process', `/cellar/lots/${lot.id}/processes/${p.id}`, t('Delete this process?'))">
                                    <Trash2 class="size-3.5" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="inFocus(lot.processes).length === 0"><td colspan="5" class="px-3 py-8 text-center text-muted-foreground">{{ t('No processes recorded yet.') }}</td></tr>
                    </tbody>
                </table>
            </Card>

            <!-- BOTTLING -->
            <Card v-else-if="tab === 'bottling'">
                <div v-for="b in lot.bottlings" :key="b.id" class="flex items-center gap-3 border-b border-border px-3 py-2 text-xs last:border-0">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ t(':n × :ml ml', { n: b.bottle_count, ml: b.bottle_volume_ml }) }}<template v-if="b.inventory_item_name"> → {{ b.inventory_item_name }}</template></p>
                        <p class="text-muted-foreground">{{ date(b.date) }} · {{ qty(b.volume_used) }} L<template v-if="b.note"> · {{ b.note }}</template></p>
                    </div>
                    <Button
                        v-if="canManage"
                        size="sm"
                        variant="ghost"
                        @click="remove('bottling', `/cellar/lots/${lot.id}/bottlings/${b.id}`, t('Reverse this bottling? The wine goes back into its vessels and the bottles leave stock.'))"
                    >
                        {{ t('Reverse') }}
                    </Button>
                </div>
                <p v-if="lot.bottlings.length === 0" class="p-8 text-center text-xs text-muted-foreground">{{ t('Not bottled yet.') }}</p>
            </Card>

            <!-- HISTORY -->
            <HistoryList v-else-if="tab === 'history'" :entries="history" />

            <!-- DETAILS -->
            <div v-else class="grid gap-4 lg:grid-cols-2">
                <Card class="flex flex-col gap-1.5 p-4 text-xs">
                    <SectionHeader :title="t('Details')" />
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Lot number') }}</span><span>{{ lot.lot_number }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Grape') }}</span><span>{{ lot.grape_variety }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Vintage') }}</span><span>{{ lot.vintage ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Vineyard') }}</span><span>{{ lot.vineyard ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Initial volume') }}</span><span>{{ qty(lot.initial_volume) }} L</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Harvest weight') }}</span><span>{{ lot.harvest_weight_kg ? `${qty(lot.harvest_weight_kg)} kg` : '—' }}</span></div>
                    <p v-if="lot.notes" class="mt-2 whitespace-pre-line">{{ lot.notes }}</p>
                    <Button v-if="canManage" size="sm" variant="outline" class="mt-2 w-fit" @click="open('edit')"><Pencil class="size-3.5" /> {{ t('Edit') }}</Button>
                </Card>
                <Card class="p-4 text-xs">
                    <SectionHeader :title="t('Traceability')" />
                    <p v-if="trace === undefined" class="text-muted-foreground">{{ t('Loading…') }}</p>
                    <template v-else>
                        <p class="mt-2 font-medium">{{ t('Harvest') }}</p>
                        <p v-for="h in trace.harvest" :key="h.id" class="text-muted-foreground">{{ h.date }} · {{ h.parcel?.name ?? '—' }} · {{ h.grape_variety }} · {{ qty(h.kg) }} kg<template v-if="h.grower"> · {{ h.grower }}</template></p>
                        <p v-if="trace.harvest.length === 0" class="text-muted-foreground">—</p>
                        <p class="mt-2 font-medium">{{ t('Blended from') }}</p>
                        <p v-for="(s, i) in trace.blended_from" :key="i" class="text-muted-foreground">{{ s.lot?.name }} ({{ s.lot?.lot_number }}) · {{ qty(s.volume) }} L</p>
                        <p v-if="trace.blended_from.length === 0" class="text-muted-foreground">—</p>
                        <p class="mt-2 font-medium">{{ t('Vessels') }}</p>
                        <p class="text-muted-foreground">{{ trace.vessels.map((v) => v.name).join(', ') || '—' }}</p>
                        <p class="mt-2 font-medium">{{ t('Bottlings') }}</p>
                        <p v-for="b in trace.bottlings" :key="b.id" class="text-muted-foreground">{{ b.date }} · {{ b.bottles }} × {{ b.bottle_volume_ml }} ml<template v-if="b.item"> → {{ b.item.name }}</template></p>
                        <p v-if="trace.bottlings.length === 0" class="text-muted-foreground">—</p>
                    </template>
                </Card>
            </div>
        </div>

        <LotFormPanel :open="panel === 'edit'" :lot="lot" :vessels="vessels" @close="panel = null" />
        <AnalysisPanel :open="panel === 'analysis'" :lot="lot" :record="editing.analysis" :vessel-id="focusId" @close="panel = null" />
        <AdditionPanel :open="panel === 'addition'" :lot="lot" :products="products" :record="editing.addition" :vessel-id="focusId" @close="panel = null" />
        <TransferPanel :open="panel === 'transfer'" :lot="lot" :lots="lots" :vessels="vessels" :vessel-id="focusId" @close="panel = null" />
        <ProcessPanel :open="panel === 'process'" :lot="lot" :vessel-id="focusId" @close="panel = null" />
        <TastingPanel :open="panel === 'tasting'" :lot-id="lot.id" :lot-name="lot.name" :vessels="lot.vessels" :record="editing.tasting" :vessel-id="focusId" @close="panel = null" />
        <BottlingPanel :open="panel === 'bottling'" :lot="lot" @close="panel = null" />
        <VolumePanel :open="panel === 'adjust' || panel === 'assign' || panel === 'blend-in'" :mode="panel === 'assign' ? 'assign' : panel === 'blend-in' ? 'blend-in' : 'adjust'" :lot="lot" :vessels="vessels" :vessel-id="focusId" @close="panel = null" />
    </AppLayout>
</template>
