<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRightLeft,
    CalendarCheck,
    CalendarX,
    Cylinder,
    Droplets,
    FlaskConical,
    GlassWater,
    Grape,
    Info,
    Pencil,
    RotateCcw,
    Trash2,
    Wine,
    Wrench,
} from 'lucide-vue-next';

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
import Disclosure from '@/components/ui/Disclosure.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import {
    ANALYSIS_FIELDS,
    LOT_STATUS_LABELS,
    LOT_STATUS_TONES,
    WINE_TYPE_LABELS,
    describeActivity,
    molecularSo2,
    qty,
    so2ReferenceTarget,
    so2Status,
} from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import type {
    AnalysisParam,
    AnalysisRow,
    CellarActivity,
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
import type { MenuItem } from '@/types/ui';

/**
 * A wine lot as one workspace: vitals on top, an action bar that records in
 * place, and the lot's journey as the main feed (filter by kind; edit, delete
 * or reverse from each row). The vessel switcher focuses the whole page on one
 * vessel (`?vessel=`, as a map click opens it). Details, history and
 * traceability live in the "Lot details" drawer; every reading in "All readings".
 */
const props = defineProps<{
    lot: WineLotDetail;
    nextPlanned: CellarActivity | null;
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
const shortDate = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' }) : '—');

// ── Vessel focus ────────────────────────────────────────────────────────────
const focusId = ref<string | null>(new URLSearchParams(window.location.search).get('vessel'));
const focus = computed(() => props.lot.vessels.find((v) => v.vessel_id === focusId.value) ?? null);
const vesselChoice = computed({
    get: () => focusId.value ?? '',
    set: (id: string) => {
        focusId.value = id || null;
        window.history.replaceState({}, '', id ? `/cellar/lots/${props.lot.id}?vessel=${id}` : `/cellar/lots/${props.lot.id}`);
    },
});
const vesselChoices = computed(() => [{ value: '', label: t('All vessels') }, ...props.lot.vessels.map((v) => ({ value: v.vessel_id, label: `${v.vessel_name ?? '—'} · ${qty(v.volume)} L` }))]);
const inFocus = <T extends { vessel_id?: string | null }>(rows: T[]) => (focusId.value ? rows.filter((r) => r.vessel_id === focusId.value) : rows);

// ── Panels ──────────────────────────────────────────────────────────────────
type Panel = 'edit' | 'analysis' | 'addition' | 'transfer' | 'process' | 'tasting' | 'bottling' | 'adjust' | 'assign' | 'blend-in' | 'details' | 'readings' | null;
const panel = ref<Panel>(null);
const editing = ref<{ analysis: AnalysisRow | null; addition: WineLotDetail['additions'][number] | null; tasting: WineLotDetail['tasting_notes'][number] | null }>({ analysis: null, addition: null, tasting: null });
function open(p: Exclude<Panel, null>, record: Partial<typeof editing.value> = {}): void {
    editing.value = { analysis: null, addition: null, tasting: null, ...record };
    panel.value = p;
}
watch(panel, (p) => {
    if (p !== 'details') return;
    if (props.trace === undefined) router.reload({ only: ['trace'] });
    if (props.history === undefined) router.reload({ only: ['history'] });
});

const bottled = computed(() => props.lot.status === 'BOTTLED');
/** A bottled lot is read-only — only reversing its bottling stays available. */
const canEdit = computed(() => canManage.value && !bottled.value);

async function remove(url: string, title: string): Promise<void> {
    if (!(await confirmDialog({ title, confirmLabel: t('Delete'), tone: 'danger' }))) return;
    router.delete(url, { preserveScroll: true });
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

// ── Vitals ──────────────────────────────────────────────────────────────────
const fill = computed(() => {
    if (focus.value) {
        const v = props.vessels.find((x) => x.id === focus.value!.vessel_id);
        const cap = Number(v?.capacity_liters ?? 0);

        return { now: Number(focus.value.volume), pct: cap > 0 ? (Number(focus.value.volume) / cap) * 100 : 0, label: t('of :n L capacity', { n: qty(cap) }) };
    }
    const init = Number(props.lot.initial_volume);

    return { now: Number(props.lot.current_volume), pct: init > 0 ? (Number(props.lot.current_volume) / init) * 100 : 0, label: t('of :n L initial', { n: qty(init) }) };
});

/** The newest reading of one parameter (analyses are newest first). */
function latestOf(key: AnalysisParam): { value: number; date: string } | null {
    const row = inFocus(props.lot.analyses).find((a) => a[key] !== null && a[key] !== undefined);

    return row ? { value: Number(row[key]), date: row.date } : null;
}
const free = computed(() => latestOf('free_so2'));
const total = computed(() => latestOf('total_so2'));
const ph = computed(() => latestOf('ph'));
const alcohol = computed(() => latestOf('alcohol'));
const va = computed(() => latestOf('volatile_acidity'));
const target = computed(() => so2ReferenceTarget(props.lot.wine_type));
const molecular = computed(() => molecularSo2(free.value?.value ?? null, ph.value?.value ?? null));
const so2Alert = computed(() => ['critical', 'low'].includes(so2Status(free.value?.value ?? null)));
const nextLabel = computed(() => (props.nextPlanned ? describeActivity(props.nextPlanned, t) : null));

// ── Journey feed ────────────────────────────────────────────────────────────
const KINDS: { key: string; label: string; types: string[] }[] = [
    { key: 'analysis', label: 'Readings', types: ['analysis'] },
    { key: 'addition', label: 'Additions', types: ['addition'] },
    { key: 'move', label: 'Moves', types: ['transfer', 'transfer_in', 'vessel'] },
    { key: 'process', label: 'Processes', types: ['process'] },
    { key: 'tasting', label: 'Tastings', types: ['tasting'] },
    { key: 'bottling', label: 'Bottling', types: ['bottling'] },
    { key: 'planned', label: 'Planned work', types: ['planned_done', 'planned_skipped'] },
];
const kind = ref('all');
/** In focus: events on that vessel, plus lot-wide ones (creation, additions to the whole lot…). */
const focused = computed(() => (focusId.value ? props.timeline.filter((e) => e.vessel_ids.length === 0 || e.vessel_ids.includes(focusId.value!)) : props.timeline));
const kindCounts = computed(() => Object.fromEntries(KINDS.map((k) => [k.key, focused.value.filter((e) => k.types.includes(e.type)).length])));
const feed = computed(() => {
    const types = KINDS.find((k) => k.key === kind.value)?.types;

    return types ? focused.value.filter((e) => types.includes(e.type)) : focused.value;
});

const TIMELINE_ICONS: Record<string, unknown> = {
    vessel: Cylinder,
    addition: Droplets,
    analysis: FlaskConical,
    tasting: Wine,
    transfer: ArrowRightLeft,
    transfer_in: ArrowRightLeft,
    process: Wrench,
    bottling: GlassWater,
    created: Grape,
    planned_done: CalendarCheck,
    planned_skipped: CalendarX,
};
const TIMELINE_TONES: Record<string, string> = {
    addition: 'text-tag-violet-foreground',
    analysis: 'text-tag-blue-foreground',
    tasting: 'text-tag-green-foreground',
    process: 'text-tag-orange-foreground',
    bottling: 'text-tag-slate-foreground',
};

const EDIT: MenuItem = { key: 'edit', label: 'Edit', icon: Pencil };
const DELETE: MenuItem = { key: 'delete', label: 'Delete', icon: Trash2, destructive: true };
const REVERSE: MenuItem = { key: 'reverse', label: 'Reverse', icon: RotateCcw, destructive: true };

/** What can be done from a journey row — the record behind it is found by ref_id. */
function rowMenu(e: TimelineEvent): MenuItem[] {
    if (!e.ref_id) return [];
    const items = (() => {
        switch (e.type) {
            case 'addition':
            case 'analysis':
            case 'tasting':
                return canEdit.value ? [EDIT, DELETE] : [];
            case 'process':
                return canEdit.value ? [DELETE] : [];
            case 'transfer':
            case 'transfer_in':
                return canEdit.value ? [REVERSE] : [];
            case 'bottling':
                return canManage.value ? [REVERSE] : [];
            default:
                return [];
        }
    })();

    return items.map((m) => ({ ...m, label: t(m.label) }));
}

function onRow(e: TimelineEvent, action: string): void {
    const id = e.ref_id!;
    const base = `/cellar/lots/${props.lot.id}`;
    switch (e.type) {
        case 'addition': {
            const record = props.lot.additions.find((a) => a.id === id) ?? null;
            if (action === 'edit' && record) open('addition', { addition: record });
            else if (action === 'delete') void remove(`${base}/additions/${id}`, t('Delete this addition? Its stock is put back.'));
            break;
        }
        case 'analysis': {
            const record = props.lot.analyses.find((a) => a.id === id) ?? null;
            if (action === 'edit' && record) open('analysis', { analysis: record });
            else if (action === 'delete') void remove(`${base}/analyses/${id}`, t('Delete this analysis?'));
            break;
        }
        case 'tasting': {
            const record = props.lot.tasting_notes.find((n) => n.id === id) ?? null;
            if (action === 'edit' && record) open('tasting', { tasting: record });
            else if (action === 'delete') void remove(`${base}/tasting-notes/${id}`, t('Delete this tasting note?'));
            break;
        }
        case 'process':
            void remove(`${base}/processes/${id}`, t('Delete this process?'));
            break;
        case 'transfer':
        case 'transfer_in':
            void remove(`${base}/transfers/${id}`, t('Reverse this transfer? The wine goes back where it came from.'));
            break;
        case 'bottling':
            void remove(`${base}/bottlings/${id}`, t('Reverse this bottling? The wine goes back into its vessels and the bottles leave stock.'));
            break;
    }
}

// ── Trend (side column) ─────────────────────────────────────────────────────
const trendParam = ref<AnalysisParam>('free_so2');
const trendParams = computed(() => ANALYSIS_FIELDS.filter((f) => props.trend.some((r) => r[f.key] !== null && r[f.key] !== undefined)));
const trendPoints = computed(() => {
    const rows = props.trend.filter((r) => r[trendParam.value] !== null && r[trendParam.value] !== undefined && (!focusId.value || r.vessel_id === focusId.value));
    const byDay = new Map<string, number[]>();
    for (const r of [...rows].sort((a, b) => a.date.localeCompare(b.date))) {
        const day = r.date.slice(0, 10);
        byDay.set(day, [...(byDay.get(day) ?? []), Number(r[trendParam.value])]);
    }

    return [...byDay.entries()].map(([d, vals]) => ({ label: shortDate(d), value: Math.round((vals.reduce((s, v) => s + v, 0) / vals.length) * 100) / 100 }));
});

const grapeTotals = computed(() => ({
    kg: props.lot.grapes.reduce((n, g) => n + Number(g.weight_kg ?? 0), 0),
    cost: props.lot.grapes.reduce((n, g) => n + (g.price_per_kg && g.weight_kg ? Math.round(g.price_per_kg.minor * Number(g.weight_kg)) : 0), 0),
}));

const readingMenu = computed<MenuItem[]>(() => (canEdit.value ? [EDIT, DELETE].map((m) => ({ ...m, label: t(m.label) })) : []));
</script>

<template>
    <AppLayout :title="lot.name">
        <div class="flex flex-col gap-4">
            <Link href="/cellar" class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft class="size-3.5" /> {{ t('Cellar') }}</Link>
            <PageHeader :title="lot.name" :description="[lot.lot_number, lot.grape_variety, lot.vintage, lot.vineyard].filter(Boolean).join(' · ')">
                <template #actions>
                    <span :class="cn('px-2 py-0.5 text-[11px] font-medium', LOT_STATUS_TONES[lot.status])">{{ t(LOT_STATUS_LABELS[lot.status] ?? lot.status) }}</span>
                    <span v-if="lot.wine_type" class="bg-muted px-2 py-0.5 text-[11px]">{{ t(WINE_TYPE_LABELS[lot.wine_type]) }}</span>
                    <Select v-if="lot.vessels.length > 1 || focusId" v-model="vesselChoice" :options="vesselChoices" :aria-label="t('Show vessel')" class="w-52" />
                    <Button v-if="canManage" size="sm" variant="outline" @click="open('edit')"><Pencil class="size-3.5" /> {{ t('Edit') }}</Button>
                    <Button v-if="can('cellar.delete')" size="sm" variant="ghost" :aria-label="t('Delete lot')" @click="deleteLot"><Trash2 class="size-3.5" /></Button>
                </template>
            </PageHeader>
            <CellarTabs current="lots" />

            <div v-if="blendSources.length" class="border border-tag-violet-foreground/30 bg-tag-violet px-3 py-2 text-xs text-tag-violet-foreground">
                <p class="font-medium">{{ t('Blend sources · :n', { n: blendSources.length }) }}</p>
                <p class="mt-1 flex flex-wrap gap-x-3">
                    <Link v-for="s in blendSources" :key="s.lot_id" :href="`/cellar/lots/${s.lot_id}`" class="underline-offset-2 hover:underline">
                        {{ s.name }} · {{ qty(s.liters) }} L ({{ Math.round((Number(s.liters) / blendSources.reduce((a, b) => a + Number(b.liters), 0)) * 100) }}%)
                    </Link>
                </p>
            </div>

            <!-- Vitals -->
            <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-5">
                <StatCard :label="focus ? t('Volume in :vessel', { vessel: focus.vessel_name ?? '' }) : t('Volume')" :value="`${qty(fill.now)} L`" :hint="`${Math.round(fill.pct)}% · ${fill.label}`" />
                <StatCard
                    :label="t('Free / total SO₂')"
                    :value="free ? `${Math.round(free.value)} / ${total ? Math.round(total.value) : '—'}` : '—'"
                    :hint="free ? [molecular !== null ? t('molecular :m', { m: qty(molecular) }) : null, t('target :n', { n: target }), shortDate(free.date)].filter(Boolean).join(' · ') : t('No reading yet')"
                    :alert="so2Alert"
                />
                <StatCard
                    :label="t('pH')"
                    :value="ph ? qty(ph.value) : '—'"
                    :hint="[alcohol ? t('alc :a %', { a: qty(alcohol.value) }) : null, va ? t('VA :v g/L', { v: qty(va.value) }) : null].filter(Boolean).join(' · ') || undefined"
                    :alert="(va?.value ?? 0) > 0.7"
                />
                <StatCard v-if="finance" :label="t('Cost / L')" :value="lot.cost_breakdown ? money(lot.cost_breakdown.per_liter) : '—'" :hint="lot.cost_breakdown ? t(':total total', { total: money(lot.cost_breakdown.total) }) : undefined" />
                <Link
                    :href="nextPlanned ? '/cellar/today' : '/cellar/plan'"
                    :class="cn('flex flex-col justify-between border p-4 hover:bg-accent', nextPlanned?.overdue ? 'border-tag-orange-foreground/40 bg-tag-orange/40' : 'border-border')"
                >
                    <span class="text-xs text-muted-foreground">{{ t('Next') }}</span>
                    <template v-if="nextPlanned">
                        <span class="text-sm font-semibold">{{ nextLabel }}</span>
                        <span class="text-[11px] text-muted-foreground">
                            {{ nextPlanned.overdue ? t('overdue since :d', { d: shortDate(nextPlanned.planned_for) }) : shortDate(nextPlanned.planned_for) }}<template v-if="nextPlanned.vessel"> · {{ nextPlanned.vessel.name }}</template><template v-if="nextPlanned.round"> · {{ nextPlanned.round.name }}</template>
                        </span>
                    </template>
                    <template v-else>
                        <span class="text-sm font-semibold">{{ t('Nothing planned') }}</span>
                        <span class="text-[11px] text-muted-foreground">{{ t('Plan work →') }}</span>
                    </template>
                </Link>
            </div>

            <!-- Action bar -->
            <div class="flex flex-wrap items-center gap-2 border border-border bg-muted/40 p-2">
                <template v-if="canEdit">
                    <Button size="sm" @click="open('analysis')"><FlaskConical class="size-3.5" /> {{ t('Reading') }}</Button>
                    <Button size="sm" variant="outline" @click="open('addition')"><Droplets class="size-3.5" /> {{ t('Addition') }}</Button>
                    <Button size="sm" variant="outline" @click="open('transfer')"><ArrowRightLeft class="size-3.5" /> {{ t('Move wine') }}</Button>
                    <Button size="sm" variant="outline" @click="open('process')"><Wrench class="size-3.5" /> {{ t('Process') }}</Button>
                    <Button size="sm" variant="outline" @click="open('tasting')"><Wine class="size-3.5" /> {{ t('Tasting') }}</Button>
                    <Button size="sm" variant="outline" @click="open('bottling')"><GlassWater class="size-3.5" /> {{ t('Bottle') }}</Button>
                </template>
                <span class="flex-1" />
                <Button size="sm" variant="ghost" @click="open('details')"><Info class="size-3.5" /> {{ t('Lot details') }}</Button>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                <!-- Journey -->
                <Card class="min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5 border-b border-border px-3 py-2.5">
                        <span class="mr-2 text-sm font-semibold">{{ t('Journey') }}</span>
                        <button type="button" :aria-pressed="kind === 'all'" :class="cn('px-2 py-1 text-[11px]', kind === 'all' ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-accent')" @click="kind = 'all'">
                            {{ t('All') }} {{ focused.length }}
                        </button>
                        <template v-for="k in KINDS" :key="k.key">
                            <button
                                v-if="kindCounts[k.key]"
                                type="button"
                                :aria-pressed="kind === k.key"
                                :class="cn('px-2 py-1 text-[11px]', kind === k.key ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-accent')"
                                @click="kind = k.key"
                            >
                                {{ t(k.label) }} {{ kindCounts[k.key] }}
                            </button>
                        </template>
                    </div>
                    <ol>
                        <li v-for="(e, i) in feed" :key="`${e.type}-${e.ref_id ?? i}`" class="grid grid-cols-[64px_16px_minmax(0,1fr)_auto] items-start gap-2.5 border-b border-border px-3 py-2.5 text-xs last:border-0">
                            <span class="pt-0.5 text-[11px] text-muted-foreground">{{ shortDate(e.date) }}</span>
                            <component :is="TIMELINE_ICONS[e.type] ?? Wrench" :class="cn('mt-0.5 size-3.5', TIMELINE_TONES[e.type] ?? 'text-muted-foreground')" />
                            <div class="min-w-0">
                                <p class="font-medium">{{ e.title }}</p>
                                <p v-if="e.detail" class="text-muted-foreground">{{ e.detail }}</p>
                            </div>
                            <DropdownMenu v-if="rowMenu(e).length" :items="rowMenu(e)" align="right" @select="(k) => onRow(e, k)" />
                        </li>
                        <li v-if="feed.length === 0" class="px-3 py-10 text-center text-xs text-muted-foreground">{{ t('Nothing recorded yet.') }}</li>
                    </ol>
                </Card>

                <!-- Side column -->
                <aside class="flex flex-col gap-3">
                    <Card v-if="trend.length" class="flex flex-col gap-2 p-3 text-xs">
                        <div class="flex items-center gap-2">
                            <Select v-model="trendParam" :options="trendParams.map((f) => ({ value: f.key, label: t(f.label) }))" :aria-label="t('Parameter')" class="flex-1" />
                            <span v-if="trendParam === 'free_so2'" class="text-muted-foreground">{{ t('target :n', { n: target }) }}</span>
                        </div>
                        <AreaChart v-if="trendPoints.length > 1" :points="trendPoints" :height="110" />
                        <p v-else class="py-4 text-center text-muted-foreground">{{ t('Two or more readings are needed for a trend.') }}</p>
                        <button type="button" class="self-start underline underline-offset-2" @click="open('readings')">{{ t('All readings (:n)', { n: inFocus(lot.analyses).length }) }}</button>
                    </Card>

                    <Card class="flex flex-col gap-2 p-3 text-xs">
                        <SectionHeader :title="t('In vessels')" />
                        <ProgressBar :value="fill.pct" />
                        <Link
                            v-for="v in lot.vessels"
                            :key="v.vessel_lot_id"
                            :href="`/cellar/vessels/${v.vessel_id}`"
                            :class="cn('flex justify-between border-b border-border py-1.5 last:border-0 hover:bg-accent', v.vessel_id === focusId && 'font-semibold')"
                        >
                            <span>{{ v.vessel_name }}</span><span class="tabular-nums">{{ qty(v.volume) }} L</span>
                        </Link>
                        <p v-if="lot.vessels.length === 0" class="text-muted-foreground">{{ t('Not in a vessel.') }}</p>
                        <div v-if="canEdit" class="flex flex-wrap gap-1.5">
                            <Button size="sm" variant="outline" @click="open('adjust')">{{ t('Adjust volume') }}</Button>
                            <Button size="sm" variant="outline" @click="open('assign')">{{ t('Assign vessel') }}</Button>
                            <Button size="sm" variant="outline" @click="open('blend-in')">{{ t('Blend in') }}</Button>
                        </div>
                    </Card>

                    <div v-if="lot.planned_additions.length" class="border border-dashed border-border p-3 text-xs">
                        <p class="mb-1 font-medium">{{ t('Planned — waiting for the cellar team · :n', { n: lot.planned_additions.length }) }}</p>
                        <p v-for="p in lot.planned_additions" :key="p.id" class="text-muted-foreground">
                            {{ p.planned_for }} · {{ p.product_name }} {{ qty(p.quantity) }} {{ p.unit }}<template v-if="p.vessel"> → {{ p.vessel.name }}</template>
                        </p>
                    </div>

                    <ProtocolCard v-if="lot.status === 'FERMENTING' || lot.fermentation_template_id" :lot="lot" :templates="templates" :can-manage="canManage" />

                    <Card v-if="lot.grapes.length || finance" class="p-3 text-xs">
                        <Disclosure :title="finance ? t('Grapes & cost') : t('Grapes')" :summary="finance && lot.cost_breakdown ? money(lot.cost_breakdown.total) : undefined">
                            <table class="mt-1 w-full">
                                <thead class="text-[10px] uppercase text-muted-foreground">
                                    <tr>
                                        <th class="py-1 text-left font-medium">{{ t('Variety') }}</th>
                                        <th class="py-1 text-right font-medium">%</th>
                                        <th class="py-1 text-right font-medium">kg</th>
                                        <th v-if="finance" class="py-1 text-right font-medium">{{ t('Cost') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="g in lot.grapes" :key="g.id" class="border-t border-border">
                                        <td class="py-1">{{ g.grape_variety }}<span v-if="finance && g.price_per_kg" class="text-muted-foreground"> · {{ money(g.price_per_kg.minor) }}/kg</span></td>
                                        <td class="py-1 text-right tabular-nums">{{ qty(g.percentage) }}</td>
                                        <td class="py-1 text-right tabular-nums">{{ qty(g.weight_kg) }}</td>
                                        <td v-if="finance" class="py-1 text-right tabular-nums">{{ g.price_per_kg && g.weight_kg ? money(Math.round(g.price_per_kg.minor * Number(g.weight_kg))) : '—' }}</td>
                                    </tr>
                                    <tr v-if="lot.grapes.length > 1" class="border-t border-border font-medium">
                                        <td class="py-1">{{ t('Total') }}</td>
                                        <td />
                                        <td class="py-1 text-right tabular-nums">{{ qty(grapeTotals.kg) }}</td>
                                        <td v-if="finance" class="py-1 text-right tabular-nums">{{ money(grapeTotals.cost) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-if="finance && lot.cost_breakdown && lot.cost_breakdown.total > 0" class="mt-3 flex flex-col gap-1 border-t border-border pt-2">
                                <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Grape cost') }}</span><span>{{ money(lot.cost_breakdown.grape) }}</span></div>
                                <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Additions') }}</span><span>{{ money(lot.cost_breakdown.additions) }}</span></div>
                                <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Linked costs') }}</span><span>{{ money(lot.cost_breakdown.linked) }}</span></div>
                                <div v-if="lot.cost_breakdown.carried" class="flex justify-between"><span class="text-muted-foreground">{{ t('Carried in by blends') }}</span><span>{{ money(lot.cost_breakdown.carried) }}</span></div>
                                <div class="flex justify-between font-semibold"><span>{{ t('Total') }}</span><span>{{ money(lot.cost_breakdown.total) }}</span></div>
                                <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Per 750 ml bottle') }}</span><span>{{ money(lot.cost_breakdown.per_bottle_750) }}</span></div>
                            </div>
                        </Disclosure>
                    </Card>
                </aside>
            </div>
        </div>

        <!-- All readings -->
        <SidePanel :open="panel === 'readings'" :title="t('All readings')" :subtitle="focus ? `${lot.name} · ${focus.vessel_name}` : lot.name" @close="panel = null">
            <div class="flex flex-col">
                <div v-for="a in inFocus(lot.analyses)" :key="a.id" class="flex gap-2 border-b border-border py-2 text-xs last:border-0">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ date(a.date) }}<template v-if="a.vessel_name"> · {{ a.vessel_name }}</template></p>
                        <p>
                            <span v-for="f in ANALYSIS_FIELDS.filter((x) => a[x.key] !== null && a[x.key] !== undefined)" :key="f.key" class="mr-3 inline-block">
                                <span class="text-muted-foreground">{{ t(f.label) }}</span> {{ qty(a[f.key]) }}
                            </span>
                        </p>
                        <p v-if="a.note" class="text-muted-foreground">{{ a.note }}</p>
                    </div>
                    <DropdownMenu
                        v-if="readingMenu.length"
                        :items="readingMenu"
                        align="right"
                        @select="(k) => (k === 'edit' ? open('analysis', { analysis: a }) : remove(`/cellar/lots/${lot.id}/analyses/${a.id}`, t('Delete this analysis?')))"
                    />
                </div>
                <p v-if="inFocus(lot.analyses).length === 0" class="py-8 text-center text-xs text-muted-foreground">{{ t('No analyses yet.') }}</p>
            </div>
        </SidePanel>

        <!-- Lot details: details, traceability, history -->
        <SidePanel :open="panel === 'details'" :title="t('Lot details')" :subtitle="lot.name" @close="panel = null">
            <div class="flex flex-col gap-5 text-xs">
                <section class="flex flex-col gap-1.5">
                    <SectionHeader :title="t('Details')" />
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Lot number') }}</span><span>{{ lot.lot_number }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Grape') }}</span><span>{{ lot.grape_variety }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Vintage') }}</span><span>{{ lot.vintage ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Vineyard') }}</span><span>{{ lot.vineyard ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Initial volume') }}</span><span>{{ qty(lot.initial_volume) }} L</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Harvest weight') }}</span><span>{{ lot.harvest_weight_kg ? `${qty(lot.harvest_weight_kg)} kg` : '—' }}</span></div>
                    <div v-if="lot.harvest_weight_kg && Number(lot.harvest_weight_kg) > 0" class="flex justify-between">
                        <span class="text-muted-foreground">{{ t('Yield') }}</span><span>{{ qty((Number(lot.initial_volume) / Number(lot.harvest_weight_kg)) * 100) }} L / 100 kg</span>
                    </div>
                    <p v-if="lot.notes" class="mt-2 whitespace-pre-line">{{ lot.notes }}</p>
                </section>
                <section>
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
                </section>
                <section>
                    <SectionHeader :title="t('History')" />
                    <HistoryList :entries="history" />
                </section>
            </div>
        </SidePanel>

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
