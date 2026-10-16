<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Printer, Save, Search, Trash2, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import PrintSheet from '@/components/cellar/PrintSheet.vue';
import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import Card from '@/components/ui/Card.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS, WINE_TYPE_LABELS, lotLabel, parseDecimal, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { csrfHeader } from '@/lib/csrf';
import { formatMoney } from '@/lib/money';
import type { AnalysisParam, CellarPreselect, LotOption, Vessel, WineType } from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { ComboboxOption } from '@/types/ui';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * Cellar › Blend — one workbench. Pick the lots and slide their ratios; the
 * projected blend (pH averaged through [H⁺]), its grapes, type and cost per
 * litre update live against each parameter's range, with what's out of range
 * spelled out. Keep promising trials, reopen them, print the sheet, then make
 * the blend. "By exact litres" is the other way in: pick vessels and the
 * litres to pull from each.
 */
interface Trial {
    id: string;
    name: string;
    components: { wine_lot_id: string; ratio: number }[];
    target_volume: string | null;
    destination_vessel_id: string | null;
    projection: Projection | null;
    created_at: string | null;
    created_by: string | null;
}

interface Projection {
    target_volume: string;
    components: { wine_lot_id: string; lot_number: string; name: string; ratio: number; volume: string; available: string; enough: boolean }[];
    analysis: Partial<Record<AnalysisParam, { value: number | null; grade: 'optimal' | 'watch' | 'concern' | null; coverage: number }>>;
    composition: Record<string, number>;
    wine_type: WineType | null;
    cost_per_liter: number;
    feasible: boolean;
    ranges: Partial<Record<AnalysisParam, { optimal: [number, number]; watch: [number, number] }>>;
}

const props = defineProps<{ vessels: Vessel[]; lots: LotOption[]; preselect: CellarPreselect; trials: Trial[] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const canManage = computed(() => can('cellar.manage'));
const currency = computed(() => page.props.org?.default_currency ?? 'EUR');
const locale = computed(() => page.props.locale);

const mode = ref<'ratio' | 'litres'>('ratio');
const busy = ref(false);

// ══ By ratio ════════════════════════════════════════════════════════════════
const ratios = reactive<Record<string, string>>({});
const ratioLots = computed(() => props.lots.filter((l) => ratios[l.id] !== undefined));
const ratioTotal = computed(() => ratioLots.value.reduce((n, l) => n + (parseDecimal(ratios[l.id]) ?? 0), 0));
const targetVolume = ref('');
const ratioDest = ref<string | null>(null);
const ratioName = ref('');
const projection = ref<Projection | null>(null);
const projectionError = ref<string | null>(null);

const lotPicker = computed<ComboboxOption[]>(() =>
    props.lots
        .filter((l) => ratios[l.id] === undefined)
        .map((l) => ({ value: l.id, label: lotLabel(l.name, l.vintage), description: `${qty(l.current_volume)} L · ${l.vessels.map((v) => v.name).join(', ')}`, keywords: [l.lot_number, l.grape_variety ?? ''] })),
);
const addLot = ref<string | null>(null);
watch(addLot, (id) => {
    if (id) ratios[id] = '';
    addLot.value = null;
    if (id && ratioLots.value.length > 1 && ratioLots.value.every((l) => l.id === id || parseDecimal(ratios[l.id]) === null)) distribute();
});

function distribute(): void {
    const n = ratioLots.value.length;
    if (n === 0) return;
    const each = Math.floor((100 / n) * 100) / 100;
    ratioLots.value.forEach((l, i) => (ratios[l.id] = String(i === n - 1 ? Math.round((100 - each * (n - 1)) * 100) / 100 : each)));
}

/** Moving one slider gives the difference to the others in proportion, so the total stays 100. */
function slide(id: string, value: number): void {
    const others = ratioLots.value.filter((l) => l.id !== id);
    const rest = others.reduce((n, l) => n + (parseDecimal(ratios[l.id]) ?? 0), 0);
    const left = Math.max(0, 100 - value);
    ratios[id] = String(value);
    others.forEach((l, i) => {
        const share = rest > 0 ? (parseDecimal(ratios[l.id]) ?? 0) / rest : 1 / others.length;
        ratios[l.id] = String(i === others.length - 1 ? Math.round((left - others.slice(0, -1).reduce((n, o) => n + (parseDecimal(ratios[o.id]) ?? 0), 0)) * 10) / 10 : Math.round(left * share * 10) / 10);
    });
}

// Opened from the map with `?vessels=…`: those vessels' lots, weighted by what the vessels hold.
if (props.preselect.vessels.length) {
    const litres: Record<string, number> = {};
    for (const v of props.vessels.filter((x) => props.preselect.vessels.includes(x.id))) {
        for (const l of v.lots ?? []) if (l.lot && Number(l.volume) > 0) litres[l.wine_lot_id] = (litres[l.wine_lot_id] ?? 0) + Number(l.volume);
    }
    const sum = Object.values(litres).reduce((a, b) => a + b, 0);
    for (const [id, l] of Object.entries(litres)) if (props.lots.some((x) => x.id === id)) ratios[id] = String(Math.round((l / sum) * 1000) / 10);
    targetVolume.value = String(Math.round(sum));
}

const vesselOptions = computed<ComboboxOption[]>(() =>
    props.vessels.map((v) => ({ value: v.id, label: v.name, description: t(':n L free', { n: qty(Number(v.capacity_liters) - Number(v.current_volume)) }), keywords: [v.room ?? ''] })),
);

function ratioBody(): Record<string, unknown> {
    return {
        components: ratioLots.value.map((l) => ({ wine_lot_id: l.id, ratio: parseDecimal(ratios[l.id]) ?? 0 })),
        target_volume: parseDecimal(targetVolume.value),
        destination_vessel_id: ratioDest.value,
        name: ratioName.value.trim() || null,
    };
}

let timer: ReturnType<typeof setTimeout> | null = null;
let seq = 0;
watch(
    [() => ({ ...ratios }), targetVolume, ratioDest],
    () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(project, 300);
    },
    { deep: true, immediate: true },
);

async function project(): Promise<void> {
    const mine = ++seq;
    if (ratioLots.value.length < 2 || Math.abs(ratioTotal.value - 100) > 0.1 || (parseDecimal(targetVolume.value) === null && ratioDest.value === null)) {
        projection.value = null;
        projectionError.value = null;

        return;
    }
    try {
        const res = await fetch('/cellar/blend/project', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
            body: JSON.stringify(ratioBody()),
        });
        const json = await res.json();
        if (mine !== seq) return;
        if (res.ok) {
            projection.value = json.data as Projection;
            projectionError.value = null;
        } else {
            projection.value = null;
            projectionError.value = Object.values((json.errors ?? {}) as Record<string, string[]>)[0]?.[0] ?? json.message ?? null;
        }
    } catch {
        if (mine === seq) projectionError.value = t('Could not project the blend.');
    }
}

const GRADE_TONES: Record<string, string> = {
    optimal: 'bg-tag-green text-tag-green-foreground',
    watch: 'bg-tag-orange text-tag-orange-foreground',
    concern: 'bg-tag-red text-tag-red-foreground',
};
const GRADE_LABELS: Record<string, string> = { optimal: 'In range', watch: 'Watch', concern: 'Concern' };
const projectedRows = computed(() => ANALYSIS_FIELDS.filter((f) => projection.value?.analysis[f.key]?.value !== null && projection.value?.analysis[f.key] !== undefined));
const rangeOf = (key: AnalysisParam) => projection.value?.ranges?.[key]?.optimal ?? null;

/** What's out of range, in words — with the one obvious follow-up (SO₂ after the blend). */
const outOfRange = computed(() =>
    projectedRows.value.flatMap((f) => {
        const a = projection.value!.analysis[f.key]!;
        const r = rangeOf(f.key);
        if (a.value === null || r === null || a.grade === 'optimal' || a.grade === null) return [];
        const below = a.value < r[0];

        return [{ key: f.key, label: f.label, value: a.value, range: r, below, gap: below ? r[0] - a.value : a.value - r[1] }];
    }),
);

async function executeRatio(): Promise<void> {
    const p = projection.value;
    if (p === null || ratioDest.value === null) return;
    const dest = props.vessels.find((v) => v.id === ratioDest.value)?.name ?? '';
    const ok = await confirmDialog({
        title: t('Make this blend?'),
        description: t(':l L from :n lots into :dest. A new lot is created and the sources are drawn down.', { l: qty(p.target_volume), n: p.components.length, dest }),
        confirmLabel: t('Make blend'),
    });
    if (!ok) return;
    busy.value = true;
    router.post('/cellar/blend/by-ratio', ratioBody() as never, { onFinish: () => (busy.value = false) });
}

// ── Trials ──────────────────────────────────────────────────────────────────
const trialName = ref('');
function saveTrial(): void {
    if (ratioLots.value.length < 2) return;
    busy.value = true;
    router.post(
        '/cellar/blend/trials',
        {
            name: trialName.value.trim() || t('Trial :n', { n: props.trials.length + 1 }),
            components: ratioLots.value.map((l) => ({ wine_lot_id: l.id, ratio: parseDecimal(ratios[l.id]) ?? 0 })),
            target_volume: parseDecimal(targetVolume.value),
            destination_vessel_id: ratioDest.value,
            projection: projection.value,
        } as never,
        { preserveScroll: true, onSuccess: () => (trialName.value = ''), onFinish: () => (busy.value = false) },
    );
}
function loadTrial(trial: Trial): void {
    for (const k of Object.keys(ratios)) delete ratios[k];
    for (const c of trial.components) ratios[c.wine_lot_id] = String(c.ratio);
    targetVolume.value = trial.target_volume ? String(Number(trial.target_volume)) : '';
    ratioDest.value = trial.destination_vessel_id;
    ratioName.value = trial.name;
    mode.value = 'ratio';
}
async function deleteTrial(trial: Trial): Promise<void> {
    if (!(await confirmDialog({ title: t('Delete :name?', { name: trial.name }), confirmLabel: t('Delete'), tone: 'danger' }))) return;
    router.delete(`/cellar/blend/trials/${trial.id}`, { preserveScroll: true });
}
const lotName = (id: string) => props.lots.find((l) => l.id === id)?.name ?? '—';
const trialValue = (trial: Trial, key: AnalysisParam) => trial.projection?.analysis?.[key]?.value ?? null;

const sheet = ref<InstanceType<typeof PrintSheet> | null>(null);
const today = computed(() => new Date().toLocaleDateString(locale.value));

// ══ By exact litres ═════════════════════════════════════════════════════════
interface Source {
    vesselLotId: string;
    vesselId: string;
    vessel: string;
    room: string | null;
    lotId: string;
    lot: string;
    vintage: string | null;
    grape: string | null;
    available: number;
    capacity: number;
    reading: string;
}

const sources = computed<Source[]>(() =>
    props.vessels
        .flatMap((v) =>
            (v.lots ?? [])
                .filter((l) => l.lot && Number(l.volume) > 0)
                .map((l) => ({
                    vesselLotId: l.vessel_lot_id,
                    vesselId: v.id,
                    vessel: v.name,
                    room: v.room,
                    lotId: l.wine_lot_id,
                    lot: l.lot!.name,
                    vintage: l.lot!.vintage,
                    grape: l.lot!.grape_variety,
                    available: Number(l.volume),
                    capacity: Number(v.capacity_liters),
                    reading: [
                        l.lot!.free_so2 !== null ? `SO₂ ${qty(l.lot!.free_so2)}/${qty(l.lot!.total_so2)}` : null,
                        l.lot!.ph ? `pH ${qty(l.lot!.ph)}` : null,
                        l.lot!.alcohol ? `${qty(l.lot!.alcohol)}%` : null,
                    ]
                        .filter(Boolean)
                        .join(' · '),
                })),
        )
        .sort((a, b) => (a.room ?? '').localeCompare(b.room ?? '') || a.vessel.localeCompare(b.vessel, undefined, { numeric: true })),
);

const filter = ref('');
const shown = computed(() => {
    const q = filter.value.trim().toLowerCase();

    return q === '' ? sources.value : sources.value.filter((s) => [s.vessel, s.lot, s.grape ?? '', s.vintage ?? '', s.room ?? ''].some((x) => x.toLowerCase().includes(q)));
});

const pull = reactive<Record<string, string>>({});
const chosen = computed(() => sources.value.filter((s) => (parseDecimal(pull[s.vesselLotId]) ?? 0) > 0));
const totalPull = computed(() => chosen.value.reduce((n, s) => n + (parseDecimal(pull[s.vesselLotId]) ?? 0), 0));
const distinctLots = computed(() => new Set(chosen.value.map((s) => s.lotId)).size);
const over = (s: Source) => (parseDecimal(pull[s.vesselLotId]) ?? 0) > s.available + 0.0005;

function toggle(s: Source, on: boolean): void {
    pull[s.vesselLotId] = on ? String(s.available) : '';
}
for (const s of sources.value) if (props.preselect.vessels.includes(s.vesselId)) toggle(s, true);

const destination = ref<string | null>(null);
const blendName = ref('');
const destOptions = computed<ComboboxOption[]>(() =>
    props.vessels
        .map((v) => {
            const freed = chosen.value.filter((s) => s.vesselId === v.id).reduce((n, s) => n + (parseDecimal(pull[s.vesselLotId]) ?? 0), 0);
            const room = Number(v.capacity_liters) - Number(v.current_volume) + freed;

            return { v, room, inPlace: freed > 0 };
        })
        .filter(({ room, inPlace }) => inPlace || room > 0)
        .map(({ v, room, inPlace }) => ({
            value: v.id,
            label: v.name,
            description: inPlace ? t('in place · :n L room', { n: qty(room) }) : t(':n L free', { n: qty(room) }),
            disabled: room + 0.0005 < totalPull.value,
            keywords: [v.room ?? ''],
        })),
);
const destName = computed(() => props.vessels.find((v) => v.id === destination.value)?.name ?? '');

function blendVessels(): void {
    if (destination.value === null || chosen.value.length < 2) return;
    busy.value = true;
    router.post(
        '/cellar/blend',
        {
            destination_vessel_id: destination.value,
            name: blendName.value.trim() || null,
            sources: chosen.value.map((s) => ({ vessel_lot_id: s.vesselLotId, volume: parseDecimal(pull[s.vesselLotId]) })),
        } as never,
        { onFinish: () => (busy.value = false) },
    );
}
</script>

<template>
    <Head :title="t('Blending')" />
        <div class="flex flex-col gap-4">
            <PageHeader :title="t('Blending')" :description="t('Try ratios, see the projected wine, keep the good trials — then make it.')">
                <template v-if="mode === 'ratio'" #actions>
                    <Button size="sm" variant="outline" :disabled="!projection" @click="sheet?.print()"><Printer class="size-3.5" /> {{ t('Print sheet') }}</Button>
                    <Button v-if="canManage" size="sm" :disabled="busy || !projection || !projection.feasible || ratioDest === null" :title="ratioDest === null ? t('Pick a destination vessel first') : undefined" @click="executeRatio">{{ t('Make this blend…') }}</Button>
                </template>
            </PageHeader>
            <CellarTabs current="blend" />

            <div class="print:hidden">
                <Tabs
                    :items="[
                        { label: t('By ratio'), value: 'ratio' },
                        { label: t('By exact litres from vessels'), value: 'litres' },
                    ]"
                    :current="mode"
                    @select="mode = $event as 'ratio' | 'litres'"
                />
            </div>

            <!-- ══ BY RATIO ══ -->
            <div v-if="mode === 'ratio'" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_400px]">
                <div class="flex flex-col gap-4">
                    <Card class="flex flex-col">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
                            <SectionHeader :title="t('Sources')" />
                            <div class="flex items-center gap-2">
                                <Button size="sm" variant="ghost" :disabled="ratioLots.length < 2" @click="distribute">{{ t('Distribute evenly') }}</Button>
                                <span :class="cn('text-xs tabular-nums', ratioLots.length && Math.abs(ratioTotal - 100) > 0.1 ? 'font-semibold text-destructive' : 'text-muted-foreground')">
                                    {{ t('Total :n %', { n: qty(ratioTotal) }) }}
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-4 p-4">
                            <div v-for="l in ratioLots" :key="l.id" class="grid grid-cols-[minmax(0,180px)_minmax(0,1fr)_72px_auto] items-center gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ l.name }}</p>
                                    <p class="truncate text-[11px] text-muted-foreground">{{ l.vessels.map((v) => v.name).join(', ') || '—' }} · {{ qty(l.current_volume) }} L</p>
                                </div>
                                <input
                                    type="range"
                                    min="0"
                                    max="100"
                                    step="1"
                                    class="w-full accent-primary"
                                    :value="parseDecimal(ratios[l.id]) ?? 0"
                                    :aria-label="t('Ratio for :lot', { lot: l.name })"
                                    @input="slide(l.id, Number(($event.target as HTMLInputElement).value))"
                                />
                                <div class="flex items-center gap-1">
                                    <Input v-model="ratios[l.id]" inputmode="decimal" class="h-8 w-14 text-right" :aria-label="t('Ratio % for :lot', { lot: l.name })" />
                                    <span class="text-xs">%</span>
                                </div>
                                <button type="button" class="p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Remove :lot', { lot: l.name })" @click="delete ratios[l.id]"><X class="size-4" /></button>
                                <p
                                    v-if="projection?.components.find((c) => c.wine_lot_id === l.id)"
                                    :class="cn('col-span-4 -mt-2 text-[11px] tabular-nums', projection.components.find((c) => c.wine_lot_id === l.id)!.enough ? 'text-muted-foreground' : 'text-destructive')"
                                >
                                    {{ t('Takes :l L of :a L', { l: qty(projection.components.find((c) => c.wine_lot_id === l.id)!.volume), a: qty(projection.components.find((c) => c.wine_lot_id === l.id)!.available) }) }}
                                </p>
                            </div>
                            <FormField v-slot="{ id }" :label="ratioLots.length ? t('Add a source') : t('Pick the lots to blend')">
                                <Combobox :id="id" v-model="addLot" :options="lotPicker" :placeholder="t('Select lot…')" />
                            </FormField>
                        </div>
                        <div class="grid gap-3 border-t border-border p-4 sm:grid-cols-3">
                            <FormField v-slot="{ id }" :label="t('Blend size (L)')" :hint="t('Or leave empty and pick a vessel — its free room is used.')">
                                <Input :id="id" v-model="targetVolume" inputmode="decimal" />
                            </FormField>
                            <FormField v-slot="{ id }" :label="t('Destination vessel')">
                                <Combobox :id="id" v-model="ratioDest" :options="vesselOptions" :placeholder="t('Select vessel…')" clearable />
                            </FormField>
                            <FormField v-slot="{ id }" :label="t('Blend name (optional)')"><Input :id="id" v-model="ratioName" /></FormField>
                        </div>
                    </Card>

                    <Card class="flex flex-col">
                        <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                            <SectionHeader :title="t('Trials')" />
                            <template v-if="canManage">
                                <Input v-model="trialName" class="ml-auto h-8 w-44" :placeholder="t('Trial :n', { n: trials.length + 1 })" :aria-label="t('Trial name')" />
                                <Button size="sm" variant="outline" :disabled="busy || ratioLots.length < 2" @click="saveTrial"><Save class="size-3.5" /> {{ t('Save trial') }}</Button>
                            </template>
                        </div>
                        <div class="flex flex-col">
                            <div v-for="tr in trials" :key="tr.id" class="flex items-center gap-3 border-b border-border px-4 py-2 text-xs last:border-0">
                                <button type="button" class="min-w-0 flex-1 text-left hover:underline" @click="loadTrial(tr)">
                                    <span class="font-medium">{{ tr.name }}</span>
                                    <span class="text-muted-foreground"> · {{ tr.components.map((c) => `${lotName(c.wine_lot_id)} ${qty(c.ratio)}%`).join(' · ') }}</span>
                                </button>
                                <span class="shrink-0 tabular-nums text-muted-foreground">
                                    <template v-if="trialValue(tr, 'ph') !== null">pH {{ qty(trialValue(tr, 'ph')) }}</template>
                                    <template v-if="trialValue(tr, 'alcohol') !== null"> · {{ qty(trialValue(tr, 'alcohol')) }} %</template>
                                </span>
                                <button v-if="canManage" type="button" class="p-1 text-muted-foreground hover:text-destructive" :aria-label="t('Delete :name', { name: tr.name })" @click="deleteTrial(tr)"><Trash2 class="size-3.5" /></button>
                            </div>
                            <p v-if="trials.length === 0" class="px-4 py-6 text-center text-xs text-muted-foreground">{{ t('No saved trials yet. Save one to compare it later.') }}</p>
                        </div>
                    </Card>
                </div>

                <!-- Projected blend -->
                <aside class="flex flex-col gap-3 lg:sticky lg:top-4 lg:self-start">
                    <Card class="flex flex-col">
                        <div class="border-b border-border px-4 py-3">
                            <SectionHeader :title="t('Projected blend')" />
                            <p v-if="projection" class="text-xs text-muted-foreground">{{ qty(projection.target_volume) }} L<template v-if="projection.wine_type"> · {{ t(WINE_TYPE_LABELS[projection.wine_type]) }}</template></p>
                        </div>
                        <p v-if="projectionError" class="px-4 py-3 text-sm text-destructive" role="alert">{{ projectionError }}</p>
                        <p v-else-if="!projection" class="px-4 py-8 text-center text-xs text-muted-foreground">
                            {{ ratioLots.length < 2 ? t('Pick two or more lots.') : Math.abs(ratioTotal - 100) > 0.1 ? t('Ratios must add up to 100 %.') : t('Enter a blend size or pick a vessel.') }}
                        </p>
                        <template v-else>
                            <table class="w-full text-xs">
                                <thead class="text-[10px] uppercase tracking-wide text-muted-foreground">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-medium">{{ t('Parameter') }}</th>
                                        <th class="px-2 py-2 text-right font-medium">{{ t('Blend') }}</th>
                                        <th class="px-2 py-2 text-right font-medium">{{ t('Range') }}</th>
                                        <th class="py-2 pl-2 pr-4" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="f in projectedRows" :key="f.key" class="border-t border-border">
                                        <td class="px-4 py-2">{{ t(f.label) }}<span v-if="f.key === 'ph'" class="text-[10px] text-muted-foreground"> [H⁺]</span></td>
                                        <td class="px-2 py-2 text-right font-semibold tabular-nums">{{ qty(projection.analysis[f.key]!.value) }}</td>
                                        <td class="px-2 py-2 text-right tabular-nums text-muted-foreground">{{ rangeOf(f.key) ? `${qty(rangeOf(f.key)![0])}–${qty(rangeOf(f.key)![1])}` : '—' }}</td>
                                        <td class="py-2 pl-2 pr-4">
                                            <span v-if="projection.analysis[f.key]!.grade" :class="cn('px-1.5 py-0.5 text-[10px]', GRADE_TONES[projection.analysis[f.key]!.grade!])">{{ t(GRADE_LABELS[projection.analysis[f.key]!.grade!]!) }}</span>
                                        </td>
                                    </tr>
                                    <tr v-if="projectedRows.length === 0"><td colspan="4" class="px-4 py-4 text-center text-muted-foreground">{{ t('None of these lots has an analysis yet.') }}</td></tr>
                                </tbody>
                            </table>
                            <div class="grid grid-cols-2 gap-3 border-t border-border px-4 py-3 text-xs">
                                <div>
                                    <p class="text-[10px] uppercase text-muted-foreground">{{ t('Grapes') }}</p>
                                    <p class="font-medium">{{ Object.entries(projection.composition).map(([g, p]) => `${g} ${qty(p)}%`).join(' · ') || '—' }}</p>
                                </div>
                                <div v-if="can('finance.view')">
                                    <p class="text-[10px] uppercase text-muted-foreground">{{ t('Cost / L') }}</p>
                                    <p class="font-medium">{{ formatMoney(projection.cost_per_liter, currency) }}</p>
                                </div>
                            </div>
                            <div v-if="outOfRange.length" class="flex flex-col gap-1.5 border-t border-border px-4 py-3 text-xs">
                                <p v-for="o in outOfRange" :key="o.key" class="bg-muted px-3 py-2">
                                    {{ o.below ? t(':param :v is below the :a–:b range.', { param: t(o.label), v: qty(o.value), a: qty(o.range[0]), b: qty(o.range[1]) }) : t(':param :v is above the :a–:b range.', { param: t(o.label), v: qty(o.value), a: qty(o.range[0]), b: qty(o.range[1]) }) }}
                                    <Link v-if="o.key === 'free_so2' && o.below" href="/cellar/so2" class="ml-1 underline underline-offset-2">{{ t('Add SO₂ after blending →') }}</Link>
                                </p>
                            </div>
                            <div v-if="!projection.feasible" class="px-4 pb-4">
                                <Callout tone="warning" :title="t('Not enough wine')">{{ t('At least one lot has less wine than this blend needs.') }}</Callout>
                            </div>
                        </template>
                    </Card>
                </aside>
            </div>

            <!-- ══ BY EXACT LITRES ══ -->
            <Card v-else class="flex flex-col">
                <div class="border-b border-border p-4">
                    <SectionHeader :title="t('Blend by vessels')" />
                    <div class="relative mt-3 max-w-md">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="filter" class="pl-8" :placeholder="t('Filter by vessel, lot, variety, vintage, room...')" />
                    </div>
                </div>
                <div class="max-h-[50vh] overflow-auto">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="w-8 px-3 py-2" />
                                <th class="px-2 py-2 text-left font-medium">{{ t('Vessel') }}</th>
                                <th class="px-2 py-2 text-left font-medium">{{ t('Lot') }}</th>
                                <th class="hidden px-2 py-2 text-left font-medium sm:table-cell">{{ t('Latest analysis') }}</th>
                                <th class="px-2 py-2 text-right font-medium">{{ t('Volume to pull') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(s, i) in shown" :key="s.vesselLotId">
                                <tr v-if="i === 0 || shown[i - 1]!.room !== s.room" class="bg-muted/50">
                                    <td colspan="5" class="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ t(s.room ?? 'Main Cellar') }}</td>
                                </tr>
                                <tr :class="cn('border-t border-border', (parseDecimal(pull[s.vesselLotId]) ?? 0) > 0 && 'bg-primary/5')">
                                    <td class="px-3 py-1.5">
                                        <input type="checkbox" class="size-4 accent-primary" :checked="(parseDecimal(pull[s.vesselLotId]) ?? 0) > 0" :aria-label="s.vessel" @change="toggle(s, ($event.target as HTMLInputElement).checked)" />
                                    </td>
                                    <td class="px-2 py-1.5"><p class="font-medium">{{ s.vessel }}</p><p class="text-[11px] text-muted-foreground">{{ qty(s.available) }} / {{ qty(s.capacity) }} L</p></td>
                                    <td class="px-2 py-1.5">{{ s.lot }} <span class="text-muted-foreground">{{ [s.grape, s.vintage].filter(Boolean).join(' ') }}</span></td>
                                    <td class="hidden px-2 py-1.5 text-muted-foreground sm:table-cell">{{ s.reading || '—' }}</td>
                                    <td class="px-2 py-1">
                                        <div class="flex items-center justify-end gap-1">
                                            <Input v-model="pull[s.vesselLotId]" inputmode="decimal" :invalid="over(s)" class="h-7 w-20 text-right" :aria-label="t('Litres from :v', { v: s.vessel })" />
                                            <button type="button" class="px-1.5 text-[11px] text-muted-foreground hover:text-foreground" @click="pull[s.vesselLotId] = String(s.available)">{{ t('All') }}</button>
                                            <button type="button" class="px-1.5 text-[11px] text-muted-foreground hover:text-foreground" @click="pull[s.vesselLotId] = String(Math.round((s.available / 2) * 1000) / 1000)">{{ t('Half') }}</button>
                                            <span :class="cn('w-24 text-[11px]', over(s) ? 'text-destructive' : 'text-muted-foreground')">
                                                {{ over(s) ? t('Exceeds available volume') : t('of :n L available', { n: qty(s.available) }) }}
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="sources.length === 0"><td colspan="5" class="px-3 py-8 text-center text-muted-foreground">{{ t('No vessels contain wine right now.') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="canManage" class="grid gap-3 border-t border-border p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <FormField v-slot="{ id }" :label="t('Destination vessel')">
                        <Combobox :id="id" v-model="destination" :options="destOptions" :placeholder="t('Select vessel…')" />
                    </FormField>
                    <FormField v-slot="{ id }" :label="t('Blend name (optional)')">
                        <Input :id="id" v-model="blendName" :placeholder="t('e.g. Cuvée 2026 — Riserva component')" />
                    </FormField>
                    <Button :disabled="busy || chosen.length < 2 || destination === null || chosen.some(over)" @click="blendVessels">{{ t('Blend vessels') }}</Button>
                    <p class="text-xs text-muted-foreground sm:col-span-3">
                        {{ t(':l L selected from :v vessels · :n lots', { l: qty(totalPull), v: chosen.length, n: distinctLots }) }}
                        <template v-if="destination"> · {{ t('Combines :n vessel(s) · :l L into :dest', { n: chosen.length, l: qty(totalPull), dest: destName }) }}</template>
                    </p>
                </div>
            </Card>
        </div>

        <PrintSheet v-if="projection" ref="sheet" :title="`${t('Blend sheet')} — ${today}`">
            <p class="mb-2">{{ ratioName || t('New blend') }} · {{ qty(projection.target_volume) }} L</p>
            <table class="mb-4 w-full border-collapse">
                <thead><tr class="bg-[#f3f4f6]"><th class="border border-[#ddd] px-2 py-1 text-left">{{ t('Lot') }}</th><th class="border border-[#ddd] px-2 py-1 text-right">%</th><th class="border border-[#ddd] px-2 py-1 text-right">L</th></tr></thead>
                <tbody>
                    <tr v-for="c in projection.components" :key="c.wine_lot_id">
                        <td class="border border-[#ddd] px-2 py-1">{{ c.name }} ({{ c.lot_number }})</td>
                        <td class="border border-[#ddd] px-2 py-1 text-right">{{ qty(c.ratio) }}</td>
                        <td class="border border-[#ddd] px-2 py-1 text-right font-bold">{{ qty(c.volume) }}</td>
                    </tr>
                </tbody>
            </table>
            <table class="w-full border-collapse">
                <thead><tr class="bg-[#f3f4f6]"><th class="border border-[#ddd] px-2 py-1 text-left">{{ t('Projected analysis') }}</th><th class="border border-[#ddd] px-2 py-1 text-right" /><th class="border border-[#ddd] px-2 py-1" /></tr></thead>
                <tbody>
                    <tr v-for="f in projectedRows" :key="f.key">
                        <td class="border border-[#ddd] px-2 py-1">{{ t(f.label) }}</td>
                        <td class="border border-[#ddd] px-2 py-1 text-right">{{ qty(projection.analysis[f.key]!.value) }}</td>
                        <td class="border border-[#ddd] px-2 py-1">{{ projection.analysis[f.key]!.grade ? t(GRADE_LABELS[projection.analysis[f.key]!.grade!]!) : '' }}</td>
                    </tr>
                </tbody>
            </table>
        </PrintSheet>
</template>
