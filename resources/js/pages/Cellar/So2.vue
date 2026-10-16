<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Check, ChevronDown, ChevronRight, Copy, MessageCircle, Printer, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import PrintSheet from '@/components/cellar/PrintSheet.vue';
import So2UpdateTable from '@/components/cellar/So2UpdateTable.vue';
import Calculators from '@/components/cellar/Calculators.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import Card from '@/components/ui/Card.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { LOT_STATUS_LABELS, SO2_STATUS_BG, SO2_STATUS_LABELS, SO2_STATUS_TONES, WINE_TYPE_LABELS, dayLabel, molecularSo2, parseDecimal, qty, so2Status } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { csrfHeader } from '@/lib/csrf';
import { formatMoney, formatUnitPrice } from '@/lib/money';
import { copyText, so2ShareText } from '@/lib/so2Share';
import type { CellarPreselect, MemberOption, So2Board, So2DosePlan, So2Lot, So2Status, So2Vessel } from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { TabItem } from '@/types/ui';

/**
 * Cellar › SO₂ — bring each wine to its target free SO₂ (or add a fixed
 * amount), or record the lab's readings. Each row starts at the wine's
 * reference target (type, +3 in oak) and the wines below it start selected;
 * the row shows molecular SO₂ now and after, and total SO₂ after against the
 * EU limit. Doses are never computed here: every selection is sent to the
 * server's dose planner (So2DosePlanner) for a live preview, and Apply / Plan
 * recompute on the server, so the numbers on screen are the numbers booked.
 * Apply opens a confirmation listing every line and the stock it draws.
 */
const props = defineProps<{ board: So2Board; members?: MemberOption[]; preselect: CellarPreselect }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const canManage = computed(() => can('cellar.manage'));
const locale = computed(() => page.props.locale);
const errors = computed(() => Object.values(page.props.errors ?? {}));

const tab = ref<'plan' | 'update'>('plan');
const modeTabs = computed<TabItem[]>(() => [
    { label: t('Plan SO₂ additions'), value: 'plan' },
    { label: t('Update SO₂ analyses'), value: 'update' },
]);

// ── Product & target ────────────────────────────────────────────────────────
const productId = ref<string>(props.board.products[0]?.id ?? '');
const product = computed(() => props.board.products.find((p) => p.id === productId.value) ?? null);
const productOptions = computed(() => props.board.products.map((p) => ({ value: p.id, label: `${p.name} (${qty(p.current_stock)} ${p.unit})` })));
const doseMode = ref<'add' | 'target'>('target');
const setAllValue = ref('30');
const note = ref('');

// ── Filters ─────────────────────────────────────────────────────────────────
const statusFilter = ref<So2Status | null>(null);
const lotStatus = ref('');
const material = ref('');
const search = ref('');
const onlyNeeding = ref(false);
/** Which wines the table shows: below their target, all, or with no reading in 30 days. */
const view = ref<'below' | 'all' | 'stale'>(props.preselect.lots.length ? 'all' : 'below');
const belowTarget = (lot: So2Lot) => lot.free_so2 !== null && lot.free_so2 < lot.reference_target;
const stale = (lot: So2Lot) => lot.days_since === null || lot.days_since >= 30;
const viewCounts = computed(() => ({ below: props.board.lots.filter(belowTarget).length, all: props.board.lots.length, stale: props.board.lots.filter(stale).length }));

const MATERIALS = computed(() => [
    { value: '', label: t('All vessels') },
    { value: 'inox', label: t('Inox / stainless') },
    { value: 'oak', label: t('Oak') },
    { value: 'barrique', label: t('Barrique') },
    { value: 'other', label: t('Other') },
]);
const LOT_STATUSES = computed(() => [
    { value: '', label: t('All statuses') },
    ...['FERMENTING', 'AGING', 'READY'].map((s) => ({ value: s, label: t(LOT_STATUS_LABELS[s]!) })),
]);

function materialOf(lot: So2Lot): string {
    const v = lot.vessels[0];
    if (!v) return 'other';
    const m = `${v.material ?? ''} ${v.type}`.toLowerCase();
    if (m.includes('barrique')) return 'barrique';
    if (v.oak) return 'oak';
    if (/inox|stainless|steel|tank/.test(m)) return 'inox';

    return 'other';
}

// ── Values & selection (keys: lotId or lotId:vesselId) ────────────────────────
const values = reactive<Record<string, string>>({});
const selected = ref<string[]>([]);
const expanded = ref<string[]>([]);

/** Target mode starts every row at its reference target; add mode starts empty. */
function initValues(): void {
    for (const key of Object.keys(values)) delete values[key];
    const target = doseMode.value === 'target';
    for (const lot of props.board.lots) {
        values[lot.id] = target ? String(lot.reference_target) : '';
        for (const v of lot.vessels) values[`${lot.id}:${v.id}`] = target && v.reference_target !== lot.reference_target ? String(v.reference_target) : '';
    }
}

/** Start with the wines that need it: those passed in the link, else (target mode) those below target. */
function autoSelect(): void {
    if (props.preselect.lots.length) {
        selected.value = props.board.lots.filter((l) => props.preselect.lots.includes(l.id)).map((l) => l.id);

        return;
    }
    selected.value = doseMode.value === 'target' ? props.board.lots.filter(belowTarget).map((l) => l.id) : [];
}
watch(
    () => props.board.lots,
    () => {
        initValues();
        autoSelect();
    },
    { immediate: true },
);

const vkey = (lot: So2Lot, v: So2Vessel) => `${lot.id}:${v.id}`;
const isSel = (key: string) => selected.value.includes(key);
function setSel(key: string, on: boolean): void {
    if (on && !isSel(key)) selected.value = [...selected.value, key];
    if (!on && isSel(key)) selected.value = selected.value.filter((k) => k !== key);
}

const needs = (lot: So2Lot) => ['critical', 'low', 'no_data'].includes(lot.so2_status) || (parseDecimal(values[lot.id]) ?? 0) > 0;

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.board.lots.filter((lot) => {
        if (view.value === 'below' && !belowTarget(lot) && !isSel(lot.id)) return false;
        if (view.value === 'stale' && !stale(lot)) return false;
        if (statusFilter.value !== null && lot.so2_status !== statusFilter.value) return false;
        if (lotStatus.value !== '' && lot.status !== lotStatus.value) return false;
        if (material.value !== '' && materialOf(lot) !== material.value) return false;
        if (onlyNeeding.value && !needs(lot)) return false;
        if (q !== '' && ![lot.name, lot.lot_number, lot.grape_variety ?? '', ...lot.vessels.map((v) => v.name)].some((s) => s.toLowerCase().includes(q))) return false;

        return true;
    });
});

/** Typing a value selects the row; clearing it to zero deselects it. */
function onValue(key: string, value: string): void {
    values[key] = value;
    const n = parseDecimal(value);
    if (n !== null) setSel(key, n > 0);
}

function toggleExpand(lot: So2Lot): void {
    if (expanded.value.includes(lot.id)) {
        expanded.value = expanded.value.filter((id) => id !== lot.id);

        return;
    }
    expanded.value = [...expanded.value, lot.id];
    // Expanding seeds each vessel with the lot's value and carries a lot-level selection down.
    for (const v of lot.vessels) {
        if ((values[vkey(lot, v)] ?? '') === '') values[vkey(lot, v)] = values[lot.id] ?? '';
        if (isSel(lot.id)) setSel(vkey(lot, v), true);
    }
    setSel(lot.id, false);
}

function toggleLot(lot: So2Lot): void {
    if (expanded.value.includes(lot.id)) {
        const keys = lot.vessels.map((v) => vkey(lot, v));
        const allOn = keys.every(isSel);
        keys.forEach((k) => setSel(k, !allOn));

        return;
    }
    setSel(lot.id, !isSel(lot.id));
}

function setAll(): void {
    for (const lot of visible.value) {
        values[lot.id] = setAllValue.value;
        if (!expanded.value.includes(lot.id)) setSel(lot.id, (parseDecimal(setAllValue.value) ?? 0) > 0);
    }
}

function selectNeeding(): void {
    for (const lot of props.board.lots) {
        if ((parseDecimal(values[lot.id]) ?? 0) > 0 && !expanded.value.includes(lot.id)) setSel(lot.id, true);
        for (const v of lot.vessels) if ((parseDecimal(values[vkey(lot, v)]) ?? 0) > 0) setSel(vkey(lot, v), true);
    }
}

/**
 * The dose request: one item per vessel. A selected (collapsed) lot covers
 * every vessel holding it with the lot's value, unless a vessel has its own.
 * Built over ALL lots, so filtering never drops a selection.
 */
const items = computed(() => {
    const out: { wine_lot_id: string; vessel_id: string | null; mg_l: number }[] = [];
    for (const lot of props.board.lots) {
        const lotValue = parseDecimal(values[lot.id]) ?? 0;
        const isExpanded = expanded.value.includes(lot.id);
        if (lot.vessels.length === 0) {
            if (isSel(lot.id) && lotValue > 0) out.push({ wine_lot_id: lot.id, vessel_id: null, mg_l: lotValue });
            continue;
        }
        for (const v of lot.vessels) {
            const own = parseDecimal(values[vkey(lot, v)]);
            const chosen = isExpanded ? isSel(vkey(lot, v)) : isSel(lot.id);
            const mg = isExpanded ? (own ?? 0) : (own !== null && own > 0 ? own : lotValue);
            if (chosen && mg > 0) out.push({ wine_lot_id: lot.id, vessel_id: v.id, mg_l: mg });
        }
    }

    return out;
});

// ── Live preview from the server ────────────────────────────────────────────
const plan = ref<So2DosePlan | null>(null);
const previewError = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;
let seq = 0;

async function fetchPreview(): Promise<void> {
    const mine = ++seq;
    if (items.value.length === 0 || productId.value === '') {
        plan.value = null;
        previewError.value = null;

        return;
    }
    try {
        const res = await fetch('/cellar/so2/preview', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
            body: JSON.stringify({ enological_product_id: productId.value, mode: doseMode.value, items: items.value }),
        });
        if (mine !== seq) return;
        const body = await res.json();
        if (!res.ok) {
            plan.value = null;
            previewError.value = Object.values((body.errors ?? {}) as Record<string, string[]>)[0]?.[0] ?? body.message ?? t('Could not compute doses.');

            return;
        }
        plan.value = body.data as So2DosePlan;
        previewError.value = null;
    } catch {
        if (mine === seq) previewError.value = t('Could not compute doses.');
    }
}

// Immediate: the page opens with the wines below target already selected.
watch([items, productId, doseMode], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(fetchPreview, 250);
}, { deep: true, immediate: true });

const lineByKey = computed(() => new Map((plan.value?.lines ?? []).map((l) => [`${l.wine_lot_id}:${l.vessel_id ?? ''}`, l])));

function lotDose(lot: So2Lot): string | null {
    const lines = (plan.value?.lines ?? []).filter((l) => l.wine_lot_id === lot.id);
    if (lines.length === 0) return null;
    const total = lines.reduce((s, l) => s + Number(l.quantity), 0);

    return `${qty(total)} ${lines[0]!.unit}`;
}

function vesselDose(lot: So2Lot, v: So2Vessel): string | null {
    const l = lineByKey.value.get(`${lot.id}:${v.id}`);

    return l ? `${qty(l.quantity)} ${l.unit}` : null;
}

function projected(current: number | null, key: string, fallback?: string): number | null {
    const own = parseDecimal(values[key]);
    const v = own !== null && own > 0 ? own : parseDecimal(fallback ?? null);
    if (v === null || v <= 0) return null;

    return doseMode.value === 'target' ? v : (current ?? 0) + v;
}

/** Molecular SO₂ at the free level the row would end at (its own value, or the lot's when a vessel has none). */
function molecularAfter(ph: number | null, current: number | null, key: string, fallback?: string): number | null {
    const a = projected(current, key, fallback);

    return a === null ? null : molecularSo2(a, ph);
}
/** Total SO₂ after the addition — what the EU limit applies to. */
function totalAfter(current: number | null, total: number | null, key: string, fallback?: string): number | null {
    const a = projected(current, key, fallback);
    if (a === null || total === null) return null;

    return Math.round(total + Math.max(0, a - (current ?? 0)));
}
const isAbove = (lot: So2Lot) => doseMode.value === 'target' && lot.free_so2 !== null && lot.free_so2 >= (parseDecimal(values[lot.id]) ?? lot.reference_target);

watch(doseMode, () => {
    expanded.value = [];
    initValues();
    autoSelect();
});

const currency = computed(() => product.value?.cost_per_unit?.currency ?? page.props.org?.default_currency ?? 'EUR');
const money = (minor: number | null) => (minor === null ? '—' : formatMoney(minor, currency.value));

// ── Share / print ───────────────────────────────────────────────────────────
const copied = ref(false);
const todayLabel = computed(() => dayLabel(new Date().toISOString().slice(0, 10), locale.value));
const shareText = computed(() => (plan.value ? so2ShareText(plan.value, t, todayLabel.value, note.value) : ''));

async function copy(): Promise<void> {
    if (await copyText(shareText.value)) {
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    }
}

function whatsapp(): void {
    window.open(`https://wa.me/?text=${encodeURIComponent(shareText.value)}`, '_blank', 'noopener');
}

const sheet = ref<InstanceType<typeof PrintSheet> | null>(null);

// ── Apply / plan ────────────────────────────────────────────────────────────
const busy = ref(false);
const applied = ref<So2DosePlan | null>(null);
const planning = ref(false);
const planDate = ref(new Date().toISOString().slice(0, 10));
const planAssignee = ref('');
const planName = ref('');

function payload(): Record<string, unknown> {
    return { enological_product_id: productId.value, mode: doseMode.value, note: note.value.trim() || null, items: items.value };
}

/** "Add now…" opens a sheet listing every line and the stock it draws; this books it. */
const confirming = ref<So2DosePlan | null>(null);
function apply(): void {
    if (plan.value !== null) confirming.value = plan.value;
}
function confirmApply(): void {
    const snapshot = confirming.value;
    if (snapshot === null) return;
    busy.value = true;
    router.post('/cellar/so2/apply', payload() as never, {
        preserveScroll: true,
        onSuccess: () => {
            applied.value = snapshot;
            confirming.value = null;
            resetSelection();
        },
        onFinish: () => (busy.value = false),
    });
}

function planRound(): void {
    busy.value = true;
    router.post(
        '/cellar/so2/round',
        { ...payload(), planned_for: planDate.value, assigned_to_id: planAssignee.value || null, name: planName.value.trim() || null } as never,
        {
            preserveScroll: true,
            onSuccess: () => {
                planning.value = false;
                resetSelection();
            },
            onFinish: () => (busy.value = false),
        },
    );
}

watch(planning, (open) => {
    if (open && props.members === undefined) router.reload({ only: ['members'] });
});

function resetSelection(): void {
    selected.value = [];
    expanded.value = [];
    initValues();
    plan.value = null;
}

const stockPct = computed(() => {
    const p = plan.value;
    const stock = Number(p?.product.current_stock ?? 0);

    return p && stock > 0 ? Math.max(0, Math.min(100, (Number(p.totals.stock_after) / stock) * 100)) : 0;
});

const summaryChips = computed(() =>
    (['critical', 'low', 'ok', 'high', 'no_data'] as So2Status[]).map((s) => ({ key: s, label: t(SO2_STATUS_LABELS[s]), count: props.board.summary[s] })),
);
</script>

<template>
    <AppLayout :title="t('SO₂ management')">
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('SO₂ management')" :description="t('Plan and apply sulphite additions across your cellar')" />
            <CellarTabs current="so2" />

            <div class="print:hidden">
                <Tabs :items="modeTabs" :current="tab" @select="tab = $event as 'plan' | 'update'" />
            </div>

            <div v-if="errors.length" class="border border-destructive/40 bg-tag-red px-3 py-2 text-sm text-tag-red-foreground" role="alert">
                <p v-for="(e, i) in errors" :key="i">{{ e }}</p>
            </div>

            <So2UpdateTable v-if="tab === 'update'" :lots="board.lots" :can-manage="canManage" />
            <Disclosure v-if="tab === 'update'" :title="t('Calculators')" :summary="t('SO₂ and molecular SO₂, acid, additions, chaptalisation, EU SO₂ limits')">
                <Calculators />
            </Disclosure>

            <!-- Success view after Apply -->
            <Card v-else-if="applied" class="flex flex-col items-center gap-3 p-8 text-center">
                <Check class="size-10 text-success" />
                <h3 class="text-lg font-semibold">{{ t('SO₂ additions applied') }}</h3>
                <p class="text-sm text-muted-foreground">{{ t(':product applied to :n vessels', { product: applied.product.name, n: applied.lines.length }) }}</p>
                <table class="mt-2 w-full max-w-md text-left text-xs">
                    <tbody>
                        <tr v-for="l in applied.lines" :key="`${l.wine_lot_id}:${l.vessel_id}`" class="border-t border-border">
                            <td class="py-1.5">{{ l.lot_name }}<span v-if="l.vessel_name" class="text-muted-foreground"> → {{ l.vessel_name }}</span></td>
                            <td class="py-1.5 text-right tabular-nums">{{ qty(l.quantity) }} {{ l.unit }}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-2 flex gap-2">
                    <Button variant="outline" href="/cellar">{{ t('Back to cellar') }}</Button>
                    <Button @click="applied = null">{{ t('Plan another') }}</Button>
                </div>
            </Card>

            <template v-else>
                <!-- Status counts double as filters -->
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    <button
                        v-for="c in summaryChips"
                        :key="c.key"
                        type="button"
                        :class="
                            cn(
                                'flex flex-col items-start border bg-card px-3 py-2 text-left transition-colors',
                                statusFilter === c.key ? 'border-primary' : 'border-border hover:border-foreground/40',
                            )
                        "
                        :aria-pressed="statusFilter === c.key"
                        @click="statusFilter = statusFilter === c.key ? null : c.key"
                    >
                        <span :class="cn('px-1.5 py-0.5 text-[10px] font-medium', SO2_STATUS_BG[c.key])">{{ c.label }}</span>
                        <span class="mt-1 text-2xl font-semibold tabular-nums">{{ c.count }}</span>
                    </button>
                </div>

                <Card class="grid gap-4 p-4 sm:grid-cols-4">
                    <FormField v-slot="{ id, invalid }" :label="t('SO₂ product')">
                        <Select :id="id" :invalid="invalid" v-model="productId" :options="productOptions" :placeholder="t('Select…')" />
                        <p v-if="product" class="text-[11px] text-muted-foreground">
                            {{ t('Stock') }}: {{ qty(product.current_stock) }} {{ product.unit }}
                            <template v-if="product.cost_per_unit"> | {{ formatUnitPrice(product.cost_per_unit.minor, currency) }}/{{ product.unit }}</template>
                        </p>
                    </FormField>
                    <FormField :label="doseMode === 'add' ? t('Set all to (+mg/L)') : t('Set all targets to (mg/L)')" :hint="t('Applies to the lots shown; edit per lot below.')">
                        <div class="flex flex-wrap gap-2">
                            <Tabs
                                variant="solid"
                                :items="[
                                    { label: t('To a target'), value: 'target' },
                                    { label: t('Add a fixed amount'), value: 'add' },
                                ]"
                                :current="doseMode"
                                @select="doseMode = $event as 'add' | 'target'"
                            />
                            <Input v-model="setAllValue" inputmode="decimal" class="w-20" :aria-label="doseMode === 'add' ? t('Set all to (+mg/L)') : t('Set all targets to (mg/L)')" />
                            <Button size="sm" variant="outline" @click="setAll">{{ t('Set') }}</Button>
                        </div>
                    </FormField>
                    <FormField v-slot="{ id, invalid }" :label="t('Note (optional)')">
                        <Input :id="id" :invalid="invalid" v-model="note" :placeholder="t('e.g. Pre-bottling correction')" />
                    </FormField>
                    <div class="text-xs text-muted-foreground">
                        <p class="mb-1 text-sm font-medium text-foreground">{{ t('Reference (mg/L)') }}</p>
                        <p>{{ t('Red') }} 28 · {{ t('White') }} 35</p>
                        <p>{{ t('Rosé') }} 32 · {{ t('Orange') }} 25</p>
                        <p>{{ t('Oak') }} +3</p>
                    </div>
                </Card>

                <Card class="flex flex-col">
                    <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                        <span class="text-sm font-semibold">{{ t('Wine lots — SO₂') }}</span>
                        <span class="text-xs text-muted-foreground">{{ t(':n selected', { n: items.length }) }}</span>
                        <div class="ml-auto flex gap-2">
                            <Button size="sm" variant="outline" @click="selectNeeding">{{ t('Select all needing SO₂') }}</Button>
                            <Button v-if="selected.length" size="sm" variant="ghost" @click="selected = []">{{ t('Clear') }}</Button>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 border-b border-border px-3 pt-3" role="group" :aria-label="t('Show')">
                        <button
                            v-for="[key, label] in ([['below', 'Below target'], ['all', 'All'], ['stale', 'No reading in 30 days']] as const)"
                            :key="key"
                            type="button"
                            :aria-pressed="view === key"
                            :class="cn('mb-3 px-2.5 py-1 text-xs', view === key ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-accent')"
                            @click="view = key"
                        >
                            {{ t(label) }} · {{ viewCounts[key] }}
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                        <Select v-model="lotStatus" :options="LOT_STATUSES" class="w-36" />
                        <Select v-model="material" :options="MATERIALS" class="w-40" />
                        <div class="relative min-w-40 flex-1">
                            <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="search" class="pl-8" :placeholder="t('Search lots...')" />
                        </div>
                        <Checkbox v-model="onlyNeeding" :label="t('Only lots needing SO₂')" />
                    </div>
                    <div class="max-h-[calc(100vh-280px)] overflow-auto">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 z-10 bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="w-8 px-3 py-2" />
                                    <th class="px-2 py-2 text-left font-medium">{{ t('Lot') }}</th>
                                    <th class="hidden px-2 py-2 text-left font-medium sm:table-cell">{{ t('Vessel') }}</th>
                                    <th class="hidden px-2 py-2 text-right font-medium md:table-cell">L</th>
                                    <th class="px-2 py-2 text-right font-medium">{{ t('Free SO₂') }}</th>
                                    <th class="px-2 py-2 text-right font-medium">{{ doseMode === 'add' ? '+mg/L' : t('Target') }}</th>
                                    <th class="px-2 py-2 text-right font-medium">{{ product?.name ?? t('Dose') }}</th>
                                    <th class="hidden px-2 py-2 text-right font-medium sm:table-cell" :title="t('Molecular SO₂ at the measured pH: free ÷ (1 + 10^(pH − 1.81)). 0.5–0.8 mg/L is the usual protective range.')">{{ t('Molecular') }}</th>
                                    <th class="hidden px-2 py-2 text-right font-medium md:table-cell">{{ t('Total after / EU') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="lot in visible" :key="lot.id">
                                    <tr
                                        :class="
                                            cn(
                                                'border-t border-border',
                                                isSel(lot.id) && 'bg-primary/5',
                                                !isSel(lot.id) && lot.so2_status === 'critical' && 'bg-tag-red/40',
                                                !isSel(lot.id) && lot.so2_status === 'low' && 'bg-tag-orange/30',
                                            )
                                        "
                                    >
                                        <td class="px-3 py-1.5">
                                            <input
                                                type="checkbox"
                                                class="size-4 accent-primary"
                                                :checked="expanded.includes(lot.id) ? lot.vessels.every((v) => isSel(vkey(lot, v))) : isSel(lot.id)"
                                                :aria-label="lot.name"
                                                @change="toggleLot(lot)"
                                            />
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <button v-if="lot.vessels.length > 1" type="button" class="mr-1 inline-flex align-middle text-muted-foreground" :aria-label="t('Per vessel')" @click="toggleExpand(lot)">
                                                <ChevronDown v-if="expanded.includes(lot.id)" class="size-3.5" />
                                                <ChevronRight v-else class="size-3.5" />
                                            </button>
                                            <button type="button" class="text-left font-medium" @click="toggleLot(lot)">{{ lot.name }}</button>
                                            <div class="text-[11px] text-muted-foreground">{{ [lot.grape_variety, lot.vintage].filter(Boolean).join(' ') }}</div>
                                        </td>
                                        <td class="hidden max-w-32 truncate px-2 py-1.5 text-muted-foreground sm:table-cell">{{ lot.vessels.map((v) => v.name).join(', ') }}</td>
                                        <td class="hidden px-2 py-1.5 text-right tabular-nums md:table-cell">{{ qty(lot.current_volume) }}</td>
                                        <td class="px-2 py-1.5 text-right tabular-nums">
                                            <span :class="cn('inline-flex items-center gap-1', SO2_STATUS_TONES[lot.so2_status])">
                                                <AlertTriangle v-if="lot.so2_status === 'critical'" class="size-3" />
                                                {{ lot.free_so2 ?? '—' }}
                                            </span>
                                            <div class="text-[10px] text-muted-foreground">{{ lot.days_since === null ? t('no reading') : lot.days_since === 0 ? t('today') : t(':nd ago', { n: lot.days_since }) }}</div>
                                        </td>
                                        <td class="px-2 py-1">
                                            <Input
                                                v-if="!expanded.includes(lot.id)"
                                                :model-value="values[lot.id]"
                                                inputmode="decimal"
                                                class="ml-auto h-8 w-16 text-right"
                                                :placeholder="doseMode === 'target' ? String(lot.reference_target) : ''"
                                                :title="doseMode === 'target' ? t(':type default :n mg/L', { type: t(WINE_TYPE_LABELS[lot.wine_type ?? 'RED'] ?? 'Wine'), n: lot.reference_target }) : undefined"
                                                :aria-label="t('mg/L for :lot', { lot: lot.name })"
                                                @update:model-value="onValue(lot.id, $event)"
                                            />
                                        </td>
                                        <td class="px-2 py-1.5 text-right font-semibold tabular-nums">
                                            <template v-if="lotDose(lot)">{{ lotDose(lot) }}</template>
                                            <span v-else-if="isAbove(lot)" class="text-[11px] font-normal text-muted-foreground">{{ t('above target') }}</span>
                                        </td>
                                        <td class="hidden px-2 py-1.5 text-right tabular-nums sm:table-cell">
                                            {{ qty(molecularSo2(lot.free_so2, lot.ph)) }}
                                            <template v-if="!expanded.includes(lot.id) && isSel(lot.id) && molecularAfter(lot.ph, lot.free_so2, lot.id) !== null">
                                                → <b class="text-tag-blue-foreground">{{ qty(molecularAfter(lot.ph, lot.free_so2, lot.id)) }}</b>
                                            </template>
                                        </td>
                                        <td class="hidden px-2 py-1.5 text-right tabular-nums md:table-cell">
                                            <template v-if="lot.compliance">
                                                <span :class="(totalAfter(lot.free_so2, lot.total_so2, lot.id) ?? lot.total_so2 ?? 0) > lot.compliance.limit ? 'font-semibold text-destructive' : ''">
                                                    {{ isSel(lot.id) && totalAfter(lot.free_so2, lot.total_so2, lot.id) !== null ? totalAfter(lot.free_so2, lot.total_so2, lot.id) : lot.total_so2 }}
                                                </span>
                                                <span class="text-muted-foreground"> / {{ lot.compliance.limit }}</span>
                                            </template>
                                            <span v-else class="text-muted-foreground">—</span>
                                        </td>
                                    </tr>
                                    <template v-if="expanded.includes(lot.id)">
                                        <tr v-for="v in lot.vessels" :key="v.id" :class="cn('border-t border-border/60 bg-muted/30', isSel(vkey(lot, v)) && 'bg-primary/10')">
                                            <td class="px-3 py-1">
                                                <input type="checkbox" class="ml-3 size-4 accent-primary" :checked="isSel(vkey(lot, v))" :aria-label="v.name" @change="setSel(vkey(lot, v), !isSel(vkey(lot, v)))" />
                                            </td>
                                            <td class="py-1 pl-7 pr-2">{{ v.name }} <span class="text-[11px] text-muted-foreground">{{ v.material ?? v.type }}</span></td>
                                            <td class="hidden sm:table-cell" />
                                            <td class="hidden px-2 text-right tabular-nums md:table-cell">{{ qty(v.volume) }}</td>
                                            <td class="px-2 text-right tabular-nums">
                                                <span :class="SO2_STATUS_TONES[so2Status(v.free_so2)]">{{ v.free_so2 ?? '—' }}</span>
                                                <span v-if="v.inherited" class="text-[10px] text-muted-foreground"> ({{ t('lot') }})</span>
                                            </td>
                                            <td class="px-2 py-1">
                                                <Input
                                                    :model-value="values[vkey(lot, v)]"
                                                    inputmode="decimal"
                                                    class="ml-auto h-7 w-16 text-right"
                                                    :placeholder="values[lot.id] || ''"
                                                    :aria-label="t('mg/L for :lot', { lot: v.name })"
                                                    @update:model-value="onValue(vkey(lot, v), $event)"
                                                />
                                            </td>
                                            <td class="px-2 text-right font-semibold tabular-nums">{{ vesselDose(lot, v) ?? '' }}</td>
                                            <td class="hidden px-2 text-right tabular-nums sm:table-cell">
                                                {{ qty(molecularSo2(v.free_so2, lot.ph)) }}
                                                <template v-if="isSel(vkey(lot, v)) && molecularAfter(lot.ph, v.free_so2, vkey(lot, v), values[lot.id]) !== null">
                                                    → <b class="text-tag-blue-foreground">{{ qty(molecularAfter(lot.ph, v.free_so2, vkey(lot, v), values[lot.id])) }}</b>
                                                </template>
                                            </td>
                                            <td class="hidden md:table-cell" />
                                        </tr>
                                    </template>
                                </template>
                                <tr v-if="visible.length === 0">
                                    <td colspan="9" class="px-3 py-8 text-center text-muted-foreground">{{ t('No lots match the filters') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <p v-if="previewError" class="text-sm text-destructive" role="alert">{{ previewError }}</p>

                <!-- Summary -->
                <Card v-if="plan && plan.lines.length" class="flex flex-col gap-4 p-4">
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ t('Vessels') }}</p>
                            <p class="text-2xl font-semibold tabular-nums">{{ plan.lines.length }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ t(':product total', { product: plan.product.name }) }}</p>
                            <p class="text-2xl font-semibold tabular-nums">{{ qty(plan.totals.quantity) }} {{ plan.lines[0]?.unit }}</p>
                        </div>
                        <div v-if="can('finance.view')">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ t('Cost') }}</p>
                            <p class="text-2xl font-semibold tabular-nums">{{ money(plan.totals.cost) }}</p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1 text-xs">
                        <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Stock after') }}</span><span class="tabular-nums">{{ qty(plan.totals.stock_after) }} / {{ qty(plan.product.current_stock) }} {{ plan.product.unit }}</span></div>
                        <ProgressBar :value="stockPct" />
                    </div>

                    <Callout v-if="plan.totals.short_by" tone="warning" :title="t('Not enough stock')">
                        {{ t('Need :need :unit, available :have :unit.', { need: qty(plan.totals.quantity), have: qty(plan.product.current_stock), unit: plan.product.unit }) }}
                    </Callout>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button size="sm" variant="outline" @click="copy">
                            <Check v-if="copied" class="size-3.5" /><Copy v-else class="size-3.5" />
                            {{ copied ? t('Copied!') : t('Copy list') }}
                        </Button>
                        <Button size="sm" variant="outline" @click="whatsapp"><MessageCircle class="size-3.5" /> WhatsApp</Button>
                        <Button size="sm" variant="outline" @click="sheet?.print()"><Printer class="size-3.5" /> {{ t('Print') }}</Button>
                        <div v-if="canManage" class="ml-auto flex gap-2">
                            <Button variant="outline" :disabled="busy" @click="planning = !planning">{{ t('Plan for cellar team') }}</Button>
                            <Button :disabled="busy || plan.totals.short_by !== null" @click="apply">{{ t('Add now to :n vessels…', { n: plan.lines.length }) }}</Button>
                        </div>
                    </div>

                    <div v-if="planning" class="grid gap-3 border-t border-border pt-4 sm:grid-cols-[160px_1fr_1fr_auto] sm:items-end">
                        <FormField v-slot="{ id, invalid }" :label="t('Date')"><Input :id="id" :invalid="invalid" v-model="planDate" type="date" /></FormField>
                        <FormField v-slot="{ id, invalid }" :label="t('Who')">
                            <Select :id="id" :invalid="invalid" v-model="planAssignee" :options="[{ value: '', label: t('Anyone') }, ...(members ?? [])]" />
                        </FormField>
                        <FormField v-slot="{ id, invalid }" :label="t('Round name (auto if empty)')"><Input :id="id" :invalid="invalid" v-model="planName" /></FormField>
                        <Button :disabled="busy" @click="planRound">{{ t('Plan :n tasks', { n: plan.lines.length }) }}</Button>
                    </div>
                </Card>
                <p v-else-if="canManage" class="text-xs text-muted-foreground">
                    {{ t('Select lots and enter mg/L to see doses.') }}
                    <Link href="/cellar/today" class="underline underline-offset-2">{{ t('Planned rounds show on Today.') }}</Link>
                </p>
            </template>
        </div>

        <SidePanel :open="confirming !== null" :title="t('Add SO₂ now?')" :subtitle="confirming ? `${confirming.product.name} · ${t(':n vessels', { n: confirming.lines.length })}` : ''" @close="confirming = null">
            <div v-if="confirming" class="flex flex-col gap-4 text-xs">
                <table class="w-full">
                    <thead class="text-[10px] uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="py-1 text-left font-medium">{{ t('Vessel') }}</th>
                            <th class="py-1 text-right font-medium">{{ t('Dose') }}</th>
                            <th class="py-1 text-right font-medium">{{ t('Free SO₂') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in confirming.lines" :key="`${l.wine_lot_id}:${l.vessel_id}`" class="border-t border-border">
                            <td class="py-1.5"><span class="font-medium">{{ l.vessel_name ?? '—' }}</span> <span class="text-muted-foreground">{{ l.lot_name }}</span></td>
                            <td class="py-1.5 text-right font-semibold tabular-nums">{{ qty(l.quantity) }} {{ l.unit }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ l.current_free_so2 ?? '?' }} → {{ qty(l.target_mg_l) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="flex flex-col gap-1 border-t border-border pt-3">
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Draws from stock') }}</span><span class="font-semibold tabular-nums">{{ qty(confirming.totals.quantity) }} {{ confirming.product.unit }}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">{{ t('Stock after') }}</span><span class="tabular-nums">{{ qty(confirming.totals.stock_after) }} {{ confirming.product.unit }}</span></div>
                    <div v-if="can('finance.view') && confirming.totals.cost !== null" class="flex justify-between"><span class="text-muted-foreground">{{ t('Cost') }}</span><span class="tabular-nums">{{ money(confirming.totals.cost) }}</span></div>
                </div>
                <p class="text-muted-foreground">{{ t('Each addition is booked on its lot and vessel.') }}</p>
            </div>
            <template #footer>
                <Button variant="outline" size="lg" @click="confirming = null">{{ t('Cancel') }}</Button>
                <Button size="lg" :disabled="busy" @click="confirmApply">{{ t('Add now') }}</Button>
            </template>
        </SidePanel>

        <PrintSheet v-if="plan" ref="sheet" :title="`${t('SO₂ correction')} — ${todayLabel}`">
            <p class="mb-3 text-xs">{{ t('Product') }}: {{ plan.product.name }}<template v-if="note"> | {{ note }}</template></p>
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr class="bg-[#f3f4f6] text-left">
                        <th class="border border-[#ddd] px-2 py-1">#</th>
                        <th class="border border-[#ddd] px-2 py-1">{{ t('Lot') }}</th>
                        <th class="border border-[#ddd] px-2 py-1">{{ t('Vessel') }}</th>
                        <th class="border border-[#ddd] px-2 py-1 text-right">{{ t('Vol.') }}</th>
                        <th class="border border-[#ddd] px-2 py-1 text-right">{{ t('Now SO₂') }}</th>
                        <th class="border border-[#ddd] px-2 py-1 text-right">{{ t('Target') }}</th>
                        <th class="border border-[#ddd] px-2 py-1 text-right">+mg/L</th>
                        <th class="border border-[#ddd] px-2 py-1 text-right">{{ plan.product.name }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in plan.lines" :key="i">
                        <td class="border border-[#ddd] px-2 py-1">{{ i + 1 }}</td>
                        <td class="border border-[#ddd] px-2 py-1 font-semibold">{{ l.lot_name }}</td>
                        <td class="border border-[#ddd] px-2 py-1">{{ l.vessel_name ?? '—' }}</td>
                        <td class="border border-[#ddd] px-2 py-1 text-right">{{ qty(l.volume) }} L</td>
                        <td class="border border-[#ddd] px-2 py-1 text-right">{{ l.current_free_so2 ?? '?' }}</td>
                        <td class="border border-[#ddd] px-2 py-1 text-right">{{ qty(l.target_mg_l) }}</td>
                        <td class="border border-[#ddd] px-2 py-1 text-right text-[#c2410c]">+{{ qty(l.add_mg_l) }}</td>
                        <td class="border border-[#ddd] px-2 py-1 text-right text-[15px] font-bold">{{ qty(l.quantity) }} {{ l.unit }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-3 font-semibold">{{ t('Total') }}: {{ qty(plan.totals.quantity) }} {{ plan.lines[0]?.unit }} {{ plan.product.name }} — {{ t(':n items', { n: plan.lines.length }) }}</p>
        </PrintSheet>
    </AppLayout>
</template>
