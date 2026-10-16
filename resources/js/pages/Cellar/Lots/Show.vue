<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRightLeft, Droplets, FlaskConical, GlassWater, Info, Pencil, Trash2, Wine, Wrench, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import AttentionList from '@/components/cellar/AttentionList.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import LotFormPanel from '@/components/cellar/LotFormPanel.vue';
import AdditionPanel from '@/components/cellar/lot/AdditionPanel.vue';
import AnalysisPanel from '@/components/cellar/lot/AnalysisPanel.vue';
import BottlingPanel from '@/components/cellar/lot/BottlingPanel.vue';
import DeleteLotDialog from '@/components/cellar/lot/DeleteLotDialog.vue';
import ProcessPanel from '@/components/cellar/lot/ProcessPanel.vue';
import TastingPanel from '@/components/cellar/lot/TastingPanel.vue';
import TransferPanel from '@/components/cellar/lot/TransferPanel.vue';
import VolumePanel from '@/components/cellar/lot/VolumePanel.vue';
import BarrelsTable from '@/components/cellar/lot/tabs/BarrelsTable.vue';
import DetailsTab from '@/components/cellar/lot/tabs/DetailsTab.vue';
import OverviewTab from '@/components/cellar/lot/tabs/OverviewTab.vue';
import RecordsTab from '@/components/cellar/lot/tabs/RecordsTab.vue';
import TablePager from '@/components/cellar/lot/tabs/TablePager.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Combobox from '@/components/ui/Combobox.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import {
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
    BarrelRow,
    BarrelsCombined,
    CellarActivity,
    CellarAttentionItem,
    FermentationTemplate,
    HistoryEntry,
    LotBottlingRow,
    LotCounts,
    LotOption,
    LotScopeInfo,
    LotTab,
    ProductOption,
    TimelineEvent,
    TraceNode,
    Vessel,
    WineLotDetail,
} from '@/types/cellar';
import type { Paginated, SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * A wine lot as one workspace, in tabs (as the prototype had it): Overview, Barrels, Analysis,
 * Additions, Tasting, Transfers, Processes and Details. A scope switcher narrows every tab to one
 * barrel (`?vessel=`, which is also where a click on the cellar map lands) and back to the whole lot.
 *
 * Everything that can grow is filtered and paged on the server: the active tab's table arrives as
 * `table` (one page of it), and a tab, a barrel, a page or a filter is a partial Inertia visit — so
 * a lot with years of readings never ships them all, and a barrel's view is a link you can share.
 */
const props = defineProps<{
    lot: WineLotDetail;
    scope: LotScopeInfo & { vessel: BarrelRow | null };
    table: Paginated<never> | null;
    bottlings: Paginated<LotBottlingRow> | null;
    counts: LotCounts;
    barrels: { rows: BarrelRow[]; combined: BarrelsCombined };
    nextPlanned: CellarActivity | null;
    attention: CellarAttentionItem[];
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
const shortDate = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' }) : '—');

const bottled = computed(() => props.lot.status === 'BOTTLED');
/** A bottled lot is read-only — only reversing its bottling stays available. */
const canEdit = computed(() => canManage.value && !bottled.value);

// ── Scope and navigation ────────────────────────────────────────────────────
const focusId = computed(() => props.scope.vessel_id);
const focus = computed(() => props.scope.vessel);
const tab = computed(() => props.scope.tab);

const SCOPED_PROPS = ['scope', 'table', 'bottlings', 'counts'];

/** One partial visit for every change of tab, barrel, page or filter: the URL carries it all. */
function visit(overrides: Record<string, unknown> = {}): void {
    const next = { tab: tab.value, vessel: focusId.value ?? undefined, kind: props.scope.kind ?? undefined, per_page: props.table?.meta.per_page, ...overrides };
    const only = [...SCOPED_PROPS, ...(next.tab === 'details' ? ['trace', 'history'] : [])];

    router.get(`/cellar/lots/${props.lot.id}`, next as never, { preserveState: true, preserveScroll: true, replace: true, only });
}
const goTab = (value: string) => visit({ tab: value, page: undefined, kind: undefined });
const goScope = (id: string | null) => visit({ vessel: id || undefined, page: undefined });
const goPage = (n: number) => visit({ page: n });
const goPerPage = (n: number) => visit({ per_page: n, page: undefined });
const goKind = (k: string | null) => visit({ kind: k ?? undefined, page: undefined });

const scopeChoice = computed({
    get: () => focusId.value ?? '',
    set: (id: string) => goScope(id || null),
});
const scopeChoices = computed(() => [
    { value: '', label: t('Whole lot') },
    ...props.lot.vessels.map((v) => ({ value: v.vessel_id, label: `${v.vessel_name ?? '—'} · ${qty(v.volume)} L` })),
]);

onMounted(() => {
    // A direct link to the Details tab arrives without its lazily-loaded parts.
    if (tab.value === 'details' && props.trace === undefined) router.reload({ only: ['trace', 'history'] });
});

const TABS = computed<TabItem[]>(() => {
    const withCount = (label: string, n: number) => (n > 0 ? `${label} (${n})` : label);

    return [
        { value: 'overview', label: t('Overview') },
        { value: 'barrels', label: withCount(t('Barrels'), props.counts.barrels) },
        { value: 'analysis', label: withCount(t('Analysis'), props.counts.analysis) },
        { value: 'additions', label: withCount(t('Additions'), props.counts.additions) },
        { value: 'tasting', label: withCount(t('Tasting'), props.counts.tasting) },
        { value: 'transfers', label: withCount(t('Transfers'), props.counts.transfers) },
        { value: 'processes', label: withCount(t('Processes'), props.counts.processes) },
        { value: 'details', label: t('Details') },
    ];
});

// ── Panels ──────────────────────────────────────────────────────────────────
type Panel = 'edit' | 'analysis' | 'addition' | 'transfer' | 'process' | 'tasting' | 'bottling' | 'adjust' | 'assign' | 'blend-in' | null;
const panel = ref<Panel>(null);
const editing = ref<{ analysis: AnalysisRow | null; addition: WineLotDetail['additions'][number] | null; tasting: WineLotDetail['tasting_notes'][number] | null }>({ analysis: null, addition: null, tasting: null });
/** The barrel a panel starts on: the one picked in a row, else the one in view. */
const panelVessel = ref<string | null>(null);
function open(p: Exclude<Panel, null>, record: Partial<typeof editing.value> = {}, vesselId: string | null = null): void {
    editing.value = { analysis: null, addition: null, tasting: null, ...record };
    panelVessel.value = vesselId ?? focusId.value;
    panel.value = p;
}

/**
 * Deletes that can be undone (the toast offers Undo) go straight through;
 * pass a title only for one that can't — reversing a bottling moves stock.
 */
async function remove(url: string, title?: string): Promise<void> {
    if (title !== undefined && !(await confirmDialog({ title, confirmLabel: t('Delete'), tone: 'danger' }))) return;
    router.delete(url, { preserveScroll: true });
}

const deletingLot = ref(false);

/** A journey row's menu: edit opens the tab that holds the record, delete and reverse act on it. */
function onRow(e: TimelineEvent, action: string): void {
    const id = e.ref_id!;
    const base = `/cellar/lots/${props.lot.id}`;
    const TAB_OF: Record<string, LotTab> = { addition: 'additions', analysis: 'analysis', tasting: 'tasting' };

    if (action === 'edit') return goTab(TAB_OF[e.type] ?? 'overview');

    switch (e.type) {
        case 'addition':
            return void remove(`${base}/additions/${id}`);
        case 'analysis':
            return void remove(`${base}/analyses/${id}`);
        case 'tasting':
            return void remove(`${base}/tasting-notes/${id}`);
        case 'process':
            return void remove(`${base}/processes/${id}`);
        case 'transfer':
        case 'transfer_in':
            return void remove(`${base}/transfers/${id}`);
        case 'bottling':
            return void remove(`${base}/bottlings/${id}`, t('Reverse this bottling? The wine goes back into its vessels and the bottles leave stock.'));
    }
}

// ── Vitals (the barrel in view, or the lot) ─────────────────────────────────
const fill = computed(() => {
    if (focus.value) {
        return { now: Number(focus.value.volume), pct: focus.value.fill_percent, label: t('of :n L capacity', { n: qty(focus.value.capacity_liters) }) };
    }
    const init = Number(props.lot.initial_volume);

    return { now: Number(props.lot.current_volume), pct: init > 0 ? (Number(props.lot.current_volume) / init) * 100 : 0, label: t('of :n L initial', { n: qty(init) }) };
});

/** The newest reading of one parameter in scope (the trend is oldest first). */
function latestOf(key: AnalysisParam): { value: number; date: string } | null {
    const rows = focusId.value ? props.trend.filter((r) => r.vessel_id === focusId.value || r.vessel_id === null) : props.trend;
    const row = [...rows].reverse().find((r) => r[key] !== null && r[key] !== undefined);

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
</script>

<template>
    <Head :title="lot.name" />
        <div class="flex flex-col gap-4">
            <Link href="/cellar" class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft class="size-3.5" /> {{ t('Cellar') }}</Link>
            <PageHeader :title="lot.name" :description="[lot.lot_number, lot.grape_variety, lot.vintage, lot.vineyard].filter(Boolean).join(' · ')">
                <template #actions>
                    <span :class="cn('px-2 py-0.5 text-[11px] font-medium', LOT_STATUS_TONES[lot.status])">{{ t(LOT_STATUS_LABELS[lot.status] ?? lot.status) }}</span>
                    <span v-if="lot.wine_type" class="bg-muted px-2 py-0.5 text-[11px]">{{ t(WINE_TYPE_LABELS[lot.wine_type]) }}</span>
                    <Combobox v-if="lot.vessels.length > 1 || focusId" v-model="scopeChoice" :options="scopeChoices" :placeholder="t('Whole lot')" :empty-text="t('No vessel matches.')" class="w-56" />
                    <Button v-if="canManage" size="sm" variant="outline" @click="open('edit')"><Pencil class="size-3.5" /> {{ t('Edit') }}</Button>
                    <Button v-if="can('cellar.delete')" size="sm" variant="ghost" :aria-label="t('Delete lot')" @click="deletingLot = true"><Trash2 class="size-3.5" /></Button>
                </template>
            </PageHeader>
            <CellarTabs current="lots" />

            <!-- One barrel in view: every tab below is narrowed to it, and the whole lot is one click away. -->
            <div v-if="focus" class="flex flex-wrap items-center gap-2 border border-primary/30 bg-primary/5 px-3 py-2 text-xs" role="status">
                <span>{{ t('Showing barrel :name', { name: focus.name }) }} · {{ qty(focus.volume) }} L<template v-if="focus.room"> · {{ focus.room }}</template></span>
                <button type="button" class="ml-auto inline-flex items-center gap-1 underline underline-offset-2 hover:text-foreground" @click="goScope(null)"><X class="size-3.5" /> {{ t('Show whole lot') }}</button>
            </div>

            <AttentionList :items="attention" :show-lot="false" />

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
                <StatCard :label="focus ? t('Volume in :vessel', { vessel: focus.name }) : t('Volume')" :value="`${qty(fill.now)} L`" :hint="`${Math.round(fill.pct)}% · ${fill.label}`" />
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
                <Button size="sm" variant="ghost" @click="goTab('details')"><Info class="size-3.5" /> {{ t('Lot details') }}</Button>
            </div>

            <div class="overflow-x-auto"><Tabs :items="TABS" :current="tab" variant="segmented" @select="goTab" /></div>

            <OverviewTab
                v-if="tab === 'overview'"
                :lot="lot"
                :journey="table"
                :kind="scope.kind"
                :barrels="barrels"
                :trend="trend"
                :focus-id="focusId"
                :templates="templates"
                :can-manage="canManage"
                :can-edit="canEdit"
                :finance="finance"
                @kind="goKind"
                @page="goPage"
                @per-page="goPerPage"
                @row="onRow"
                @focus="goScope"
                @reading="(id) => open('analysis', {}, id)"
                @tab="goTab"
                @open="(p) => open(p)"
            />

            <Card v-else-if="tab === 'barrels'" class="overflow-hidden">
                <BarrelsTable :rows="(table?.data ?? []) as unknown as BarrelRow[]" :combined="barrels.combined" :lot-id="lot.id" :focus-id="focusId" :can-edit="canEdit" @focus="(id) => { goScope(id); }" @reading="(id) => open('analysis', {}, id)" />
                <TablePager v-if="table" :meta="table.meta" @page="goPage" @per-page="goPerPage" />
            </Card>

            <DetailsTab v-else-if="tab === 'details'" :lot="lot" :trace="trace" :history="history" />

            <RecordsTab
                v-else
                :kind="tab as 'analysis' | 'additions' | 'tasting' | 'transfers' | 'processes'"
                :table="table"
                :trend="trend"
                :focus-id="focusId"
                :lot="lot"
                :bottlings="bottlings"
                :can-edit="canEdit"
                :finance="finance"
                @page="goPage"
                @per-page="goPerPage"
                @edit="(kind, record) => open(kind, { [kind]: record })"
                @remove="remove"
            />
        </div>

        <LotFormPanel :open="panel === 'edit'" :lot="lot" :vessels="vessels" @close="panel = null" />
        <AnalysisPanel :open="panel === 'analysis'" :lot="lot" :record="editing.analysis" :vessel-id="panelVessel" @close="panel = null" />
        <AdditionPanel :open="panel === 'addition'" :lot="lot" :products="products" :record="editing.addition" :vessel-id="panelVessel" @close="panel = null" />
        <TransferPanel :open="panel === 'transfer'" :lot="lot" :lots="lots" :vessels="vessels" :vessel-id="panelVessel" @close="panel = null" />
        <ProcessPanel :open="panel === 'process'" :lot="lot" :vessel-id="panelVessel" @close="panel = null" />
        <TastingPanel :open="panel === 'tasting'" :lot-id="lot.id" :lot-name="lot.name" :vessels="lot.vessels" :record="editing.tasting" :vessel-id="panelVessel" @close="panel = null" />
        <BottlingPanel :open="panel === 'bottling'" :lot="lot" @close="panel = null" />
        <VolumePanel :open="panel === 'adjust' || panel === 'assign' || panel === 'blend-in'" :mode="panel === 'assign' ? 'assign' : panel === 'blend-in' ? 'blend-in' : 'adjust'" :lot="lot" :vessels="vessels" :vessel-id="panelVessel" @close="panel = null" />
        <DeleteLotDialog :open="deletingLot" :lot-id="lot.id" :lot-name="lot.name" @close="deletingLot = false" />
</template>
