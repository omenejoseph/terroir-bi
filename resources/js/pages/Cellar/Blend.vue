<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Printer, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import PrintSheet from '@/components/cellar/PrintSheet.vue';
import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import Card from '@/components/ui/Card.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SectionHeader from '@/components/ui/SectionHeader.vue';
import StackedBar from '@/components/ui/StackedBar.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS, WINE_TYPE_LABELS, parseDecimal, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { csrfHeader } from '@/lib/csrf';
import { formatMoney } from '@/lib/money';
import type { AnalysisParam, LotOption, Vessel, WineType } from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { ComboboxOption } from '@/types/ui';

/**
 * Cellar › Blend — two ways to make a blend:
 * - by vessels: pick the vessels (and litres) to pull, and a destination
 *   (an empty vessel or one of the sources, in place) → a new lot;
 * - by lot ratios: a projection of the blend's analysis, graded, with cost
 *   per litre and the litres each lot gives — then execute or print it.
 */
const props = defineProps<{ vessels: Vessel[]; lots: LotOption[] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const canManage = computed(() => can('cellar.manage'));
const currency = computed(() => page.props.org?.default_currency ?? 'EUR');

// ── By vessels ──────────────────────────────────────────────────────────────
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
    props.vessels.flatMap((v) =>
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
    ).sort((a, b) => (a.room ?? '').localeCompare(b.room ?? '') || a.vessel.localeCompare(b.vessel, undefined, { numeric: true })),
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

const destination = ref<string | null>(null);
const blendName = ref('');
const destOptions = computed<ComboboxOption[]>(() =>
    props.vessels
        .map((v) => {
            const freed = chosen.value.filter((s) => s.vesselId === v.id).reduce((n, s) => n + (parseDecimal(pull[s.vesselLotId]) ?? 0), 0);
            const room = Number(v.capacity_liters) - Number(v.current_volume) + freed;
            const inPlace = freed > 0;

            return { v, room, inPlace };
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
const busy = ref(false);

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

// ── By ratios (projection) ──────────────────────────────────────────────────
interface Projection {
    target_volume: string;
    components: { wine_lot_id: string; lot_number: string; name: string; ratio: number; volume: string; available: string; enough: boolean }[];
    analysis: Partial<Record<AnalysisParam, { value: number | null; grade: 'optimal' | 'watch' | 'concern' | null; coverage: number }>>;
    composition: Record<string, number>;
    wine_type: WineType | null;
    cost_per_liter: number;
    feasible: boolean;
}

const ratios = reactive<Record<string, string>>({});
const ratioLots = computed(() => props.lots.filter((l) => ratios[l.id] !== undefined));
const ratioTotal = computed(() => ratioLots.value.reduce((n, l) => n + (parseDecimal(ratios[l.id]) ?? 0), 0));
const targetVolume = ref('');
const ratioDest = ref<string | null>(null);
const ratioName = ref('');
const projection = ref<Projection | null>(null);
const projectionError = ref<string | null>(null);

const lotPicker = computed<ComboboxOption[]>(() =>
    props.lots.filter((l) => ratios[l.id] === undefined).map((l) => ({ value: l.id, label: [l.name, l.vintage].filter(Boolean).join(' '), description: `${qty(l.current_volume)} L`, keywords: [l.lot_number] })),
);
const addLot = ref<string | null>(null);
watch(addLot, (id) => {
    if (id) ratios[id] = '';
    addLot.value = null;
});

function distribute(): void {
    const n = ratioLots.value.length;
    if (n === 0) return;
    const each = Math.floor((100 / n) * 100) / 100;
    ratioLots.value.forEach((l, i) => (ratios[l.id] = String(i === n - 1 ? Math.round((100 - each * (n - 1)) * 100) / 100 : each)));
}

const vesselOptions = computed<ComboboxOption[]>(() =>
    props.vessels.map((v) => ({ value: v.id, label: v.name, description: t(':n L free', { n: qty(Number(v.capacity_liters) - Number(v.current_volume)) }), keywords: [v.room ?? ''] })),
);

let timer: ReturnType<typeof setTimeout> | null = null;
watch([() => ({ ...ratios }), targetVolume, ratioDest], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(project, 300);
}, { deep: true });

function ratioBody(): Record<string, unknown> {
    return {
        components: ratioLots.value.map((l) => ({ wine_lot_id: l.id, ratio: parseDecimal(ratios[l.id]) ?? 0 })),
        target_volume: parseDecimal(targetVolume.value),
        destination_vessel_id: ratioDest.value,
        name: ratioName.value.trim() || null,
    };
}

async function project(): Promise<void> {
    if (ratioLots.value.length < 2 || Math.abs(ratioTotal.value - 100) > 0.01 || (parseDecimal(targetVolume.value) === null && ratioDest.value === null)) {
        projection.value = null;
        projectionError.value = null;

        return;
    }
    const res = await fetch('/cellar/blend/project', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
        body: JSON.stringify(ratioBody()),
    });
    const json = await res.json();
    if (res.ok) {
        projection.value = json.data as Projection;
        projectionError.value = null;
    } else {
        projection.value = null;
        projectionError.value = Object.values((json.errors ?? {}) as Record<string, string[]>)[0]?.[0] ?? json.message ?? null;
    }
}

function executeRatio(): void {
    busy.value = true;
    router.post('/cellar/blend/by-ratio', ratioBody() as never, { onFinish: () => (busy.value = false) });
}

const GRADE_TONES: Record<string, string> = {
    optimal: 'bg-tag-green text-tag-green-foreground',
    watch: 'bg-tag-orange text-tag-orange-foreground',
    concern: 'bg-tag-red text-tag-red-foreground',
};
const GRADE_LABELS: Record<string, string> = { optimal: 'Optimal', watch: 'Watch', concern: 'Concern' };
const projectedRows = computed(() => ANALYSIS_FIELDS.filter((f) => projection.value?.analysis[f.key]?.value !== null && projection.value?.analysis[f.key] !== undefined));

const sheet = ref<InstanceType<typeof PrintSheet> | null>(null);
const today = new Date().toLocaleDateString();
</script>

<template>
    <AppLayout :title="t('Blending')">
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Blending')" :description="t('Combine vessels into one — or project a blend by lot ratios.')" />
            <CellarTabs current="blend" />

            <!-- BY VESSELS -->
            <Card class="flex flex-col">
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

            <!-- BY RATIOS -->
            <Disclosure :title="t('Projected blending (by lot ratios)')" :summary="t('See the blend’s analysis and cost before you make it.')">
                <div class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <FormField v-slot="{ id }" :label="t('Add a lot')" class="min-w-64">
                            <Combobox :id="id" v-model="addLot" :options="lotPicker" :placeholder="t('Select lot…')" />
                        </FormField>
                        <Button size="sm" variant="outline" :disabled="ratioLots.length === 0" @click="distribute">{{ t('Distribute evenly') }}</Button>
                    </div>
                    <div v-for="l in ratioLots" :key="l.id" class="flex items-center gap-3 text-sm">
                        <span class="min-w-0 flex-1 truncate">{{ l.name }} <span class="text-xs text-muted-foreground">{{ l.vintage }} · {{ qty(l.current_volume) }} L</span></span>
                        <Input v-model="ratios[l.id]" inputmode="decimal" class="h-8 w-20 text-right" :aria-label="t('Ratio for :lot', { lot: l.name })" /> %
                        <button type="button" class="text-xs text-muted-foreground hover:text-destructive" @click="delete ratios[l.id]">{{ t('Remove') }}</button>
                    </div>
                    <p v-if="ratioLots.length" :class="cn('text-xs', Math.abs(ratioTotal - 100) > 0.01 ? 'text-destructive' : 'text-muted-foreground')">
                        {{ t('Total :n % — must be 100 %', { n: qty(ratioTotal) }) }}
                    </p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <FormField v-slot="{ id }" :label="t('Target volume (L)')" :hint="t('Or leave empty and pick a vessel — its free room is used.')">
                            <Input :id="id" v-model="targetVolume" inputmode="decimal" />
                        </FormField>
                        <FormField v-slot="{ id }" :label="t('Destination vessel')">
                            <Combobox :id="id" v-model="ratioDest" :options="vesselOptions" :placeholder="t('Select vessel…')" clearable />
                        </FormField>
                        <FormField v-slot="{ id }" :label="t('Blend name (optional)')"><Input :id="id" v-model="ratioName" /></FormField>
                    </div>

                    <p v-if="projectionError" class="text-sm text-destructive" role="alert">{{ projectionError }}</p>

                    <template v-if="projection">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <p class="mb-1 text-sm font-medium">{{ t('Volume breakdown') }} · {{ qty(projection.target_volume) }} L</p>
                                <StackedBar class="mb-2" :segments="projection.components.map((c) => ({ label: c.name, value: Number(c.volume), caption: `${qty(c.volume)} L` }))" />
                                <div v-for="c in projection.components" :key="c.wine_lot_id" class="flex justify-between border-b border-border py-1 text-xs last:border-0">
                                    <span>{{ c.name }} ({{ qty(c.ratio) }} %)</span>
                                    <span :class="cn('tabular-nums', !c.enough && 'text-destructive')">{{ qty(c.volume) }} L <span class="text-muted-foreground">/ {{ qty(c.available) }}</span></span>
                                </div>
                                <p class="mt-2 text-xs text-muted-foreground">
                                    <template v-if="projection.wine_type">{{ t('Projected type') }}: {{ t(WINE_TYPE_LABELS[projection.wine_type]) }} · </template>
                                    {{ Object.entries(projection.composition).map(([g, p]) => `${g} ${qty(p)}%`).join(', ') }}
                                </p>
                                <p v-if="can('finance.view')" class="text-xs">{{ t('Cost per litre') }}: <span class="font-semibold">{{ formatMoney(projection.cost_per_liter, currency) }}</span></p>
                            </div>
                            <div>
                                <p class="mb-1 text-sm font-medium">{{ t('Projected analysis') }}</p>
                                <div v-for="f in projectedRows" :key="f.key" class="flex items-center justify-between border-b border-border py-1 text-xs last:border-0">
                                    <span class="text-muted-foreground">{{ t(f.label) }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="tabular-nums">{{ qty(projection.analysis[f.key]!.value) }}</span>
                                        <span v-if="projection.analysis[f.key]!.grade" :class="cn('px-1.5 text-[10px]', GRADE_TONES[projection.analysis[f.key]!.grade!])">{{ t(GRADE_LABELS[projection.analysis[f.key]!.grade!]!) }}</span>
                                    </span>
                                </div>
                                <p v-if="projectedRows.length === 0" class="text-xs text-muted-foreground">{{ t('None of these lots has an analysis yet.') }}</p>
                            </div>
                        </div>
                        <Callout v-if="!projection.feasible" tone="warning" :title="t('Not enough wine')">{{ t('At least one lot has less wine than this blend needs.') }}</Callout>
                        <div class="flex flex-wrap gap-2">
                            <Button size="sm" variant="outline" @click="sheet?.print()"><Printer class="size-3.5" /> {{ t('Print blend sheet') }}</Button>
                            <Button v-if="canManage" :disabled="busy || !projection.feasible || ratioDest === null" @click="executeRatio">{{ t('Execute blend') }}</Button>
                            <span class="self-center text-xs text-muted-foreground">{{ t('Creates a new lot · drains :n source lot(s) · :l L total', { n: projection.components.length, l: qty(projection.target_volume) }) }}</span>
                        </div>
                    </template>
                </div>
            </Disclosure>
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
    </AppLayout>
</template>
