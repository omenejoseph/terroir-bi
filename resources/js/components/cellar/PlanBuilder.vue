<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ACTIVITY_LABELS, ACTIVITY_TYPES, METRIC_LABELS, PLANNER_METRICS, dayLabel, initials, lotLabel, parseDecimal, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import type { ActivityPayload, AnalysisParam, CellarActivityType, LotOption, MemberOption, ParcelOption, ProductOption, Vessel } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * The planner: what kind of work, for which lot or which vessels across the
 * cellar, on which day, for whom. It turns the choices into one planned item
 * per vessel (or parcel) and sends them in a single request; a round name
 * groups them and puts one card on the work-order board.
 *
 * Whole-cellar vessel selection happens on the map (the `selected` v-model is
 * shared with the page's VesselMapPicker).
 */
const props = defineProps<{
    today: string;
    meId: string | null;
    locale: string;
    lots: LotOption[];
    vessels: Vessel[];
    products: ProductOption[];
    members: MemberOption[];
    parcels: ParcelOption[];
}>();

const type = defineModel<CellarActivityType>('type', { required: true });
const scope = defineModel<'lot' | 'cellar'>('scope', { required: true });
const selected = defineModel<string[]>('selected', { required: true });

const { t } = useTranslations();

const lotId = ref<string | null>(null);
const date = ref(props.today);
const productId = ref<string | null>(null);
const freeName = ref('');
const freeUnit = ref('g');
type DoseMode = 'per_hl' | 'per_l' | 'per_vessel' | 'total';
const doseMode = ref<DoseMode>('per_hl');
const dose = ref('');
const metrics = ref<AnalysisParam[]>(['free_so2']);
const fromVessel = ref('');
const toVessel = ref('');
const liters = ref('');
const kind = ref('');
const parcelIds = ref<string[]>([]);
const assignee = ref<string | null>(null);
const note = ref('');
const roundName = ref('');
const clientError = ref<string | null>(null);
const busy = ref(false);

const lot = computed(() => props.lots.find((l) => l.id === lotId.value) ?? null);
const product = computed(() => props.products.find((p) => p.id === productId.value) ?? null);
const unit = computed(() => product.value?.unit ?? (freeUnit.value || 'g'));

const usesScope = computed(() => !['TRANSFER', 'VINEYARD'].includes(type.value));
const cellarMode = computed(() => usesScope.value && scope.value === 'cellar');
const needsLot = computed(() => type.value !== 'VINEYARD' && !cellarMode.value);

watch(lotId, () => {
    selected.value = [];
    fromVessel.value = '';
});
watch(scope, () => (selected.value = []));

/** Vessels holding wine (whole-cellar mode) — the vessel's first lot is the one planned. */
const filledVessels = computed(() => props.vessels.filter((v) => (v.lots ?? []).some((l) => l.lot !== null)));

const lotOptions = computed<ComboboxOption[]>(() =>
    props.lots.map((l) => ({
        value: l.id,
        label: lotLabel(l.name, l.vintage),
        description: `${qty(l.current_volume)} L`,
        keywords: [l.lot_number, l.grape_variety ?? ''],
    })),
);
const productOptions = computed<ComboboxOption[]>(() =>
    props.products.map((p) => ({
        value: p.id,
        label: p.name,
        description: `${p.category ?? ''} · ${t('stock')} ${qty(p.current_stock)} ${p.unit}`,
        keywords: [p.category ?? ''],
    })),
);
const allVesselOptions = computed(() =>
    props.vessels
        .filter((v) => v.id !== fromVessel.value)
        .map((v) => ({ value: v.id, label: `${v.name} · ${qty(v.current_volume)}/${qty(v.capacity_liters)} L` })),
);

/** The targets the plan will create one item for: vessel + lot + litres in it. */
interface Target {
    vesselId: string | null;
    vesselName: string;
    lotId: string;
    volume: number;
}

const targets = computed<Target[]>(() => {
    if (cellarMode.value) {
        return filledVessels.value
            .filter((v) => selected.value.includes(v.id))
            .map((v) => {
                const content = (v.lots ?? []).find((l) => l.lot !== null)!;

                return { vesselId: v.id, vesselName: v.name, lotId: content.wine_lot_id, volume: Number(content.volume) };
            });
    }
    if (lot.value === null) return [];
    const vs = lot.value.vessels;
    const chosen = selected.value.length ? vs.filter((v) => selected.value.includes(v.id)) : vs;

    return chosen.map((v) => ({ vesselId: v.id, vesselName: v.name, lotId: lot.value!.id, volume: Number(v.volume) }));
});

function quantityFor(volume: number, total: number): number {
    const d = parseDecimal(dose.value) ?? 0;
    const q = { per_hl: (d * volume) / 100, per_l: d * volume, per_vessel: d, total: total > 0 ? (d * volume) / total : 0 }[doseMode.value];

    return Math.round(q * 100) / 100;
}

interface Planned {
    type: CellarActivityType;
    planned_for: string;
    wine_lot_id: string | null;
    vessel_id: string | null;
    payload: ActivityPayload;
    label: string;
    detail: string;
}

const planned = computed<Planned[]>(() => {
    const base = { type: type.value, planned_for: date.value };
    const list = targets.value;
    const totalVol = list.reduce((s, x) => s + x.volume, 0);

    switch (type.value) {
        case 'ADDITION': {
            const name = product.value?.name ?? freeName.value.trim();
            if (name === '') return [];

            return list.map((x) => {
                const quantity = quantityFor(x.volume, totalVol);

                return {
                    ...base,
                    wine_lot_id: x.lotId,
                    vessel_id: x.vesselId,
                    payload: {
                        product_name: name,
                        enological_product_id: product.value?.id ?? null,
                        category: product.value?.category ?? null,
                        unit: unit.value,
                        quantity,
                        cost_per_unit: product.value?.cost_per_unit ?? null,
                        dose_per_hl: doseMode.value === 'per_hl' ? parseDecimal(dose.value) : null,
                    },
                    label: x.vesselName,
                    detail: `${qty(quantity)} ${unit.value}`,
                };
            });
        }
        case 'ANALYSIS':
            return list.map((x) => ({
                ...base,
                wine_lot_id: x.lotId,
                vessel_id: x.vesselId,
                payload: { metrics: metrics.value },
                label: x.vesselName,
                detail: metrics.value.map((m) => t(METRIC_LABELS[m])).join(' + '),
            }));
        case 'PROCESS': {
            if (!cellarMode.value && lot.value && selected.value.length === 0 && lot.value.vessels.length === 0) {
                return [{ ...base, wine_lot_id: lot.value.id, vessel_id: null, payload: { kind: kind.value.trim() }, label: lot.value.name, detail: kind.value }];
            }

            return list.map((x) => ({
                ...base,
                wine_lot_id: x.lotId,
                vessel_id: x.vesselId,
                payload: { kind: kind.value.trim() },
                label: x.vesselName,
                detail: kind.value,
            }));
        }
        case 'TOPPING':
            return list.map((x) => ({
                ...base,
                wine_lot_id: x.lotId,
                vessel_id: x.vesselId,
                payload: { liters: parseDecimal(liters.value) ?? 0 },
                label: x.vesselName,
                detail: `+${qty(parseDecimal(liters.value))} L`,
            }));
        case 'TRANSFER': {
            if (lot.value === null || fromVessel.value === '' || toVessel.value === '') return [];
            const from = lot.value.vessels.find((v) => v.id === fromVessel.value);
            const to = props.vessels.find((v) => v.id === toVessel.value);

            return [
                {
                    ...base,
                    wine_lot_id: lot.value.id,
                    vessel_id: fromVessel.value,
                    payload: { from_vessel_id: fromVessel.value, to_vessel_id: toVessel.value, liters: parseDecimal(liters.value) ?? 0 },
                    label: `${from?.name ?? '?'} → ${to?.name ?? '?'}`,
                    detail: `${qty(parseDecimal(liters.value))} L`,
                },
            ];
        }
        case 'VINEYARD':
            return props.parcels
                .filter((p) => parcelIds.value.includes(p.id))
                .map((p) => ({
                    ...base,
                    wine_lot_id: null,
                    vessel_id: null,
                    payload: { kind: kind.value.trim(), parcel_id: p.id, parcel_name: p.name },
                    label: p.name,
                    detail: kind.value,
                }));
    }

    return [];
});

/** Same rules, same copy as the prototype — said before the request, not after. */
function validate(): string | null {
    if (type.value === 'VINEYARD') {
        if (kind.value.trim() === '') return t('Name the task (pruning, spraying, mowing…)');
        if (parcelIds.value.length === 0) return t('Pick at least one parcel');

        return null;
    }
    if (needsLot.value && lot.value === null) return t('Pick a wine lot');
    if (type.value === 'TRANSFER') {
        if (fromVessel.value === '' || toVessel.value === '') return t('Pick source and destination vessels');
        if ((parseDecimal(liters.value) ?? 0) <= 0) return t('Enter liters');

        return null;
    }
    if (cellarMode.value && targets.value.length === 0) return t('Pick at least one vessel');
    if (!cellarMode.value && lot.value && lot.value.vessels.length === 0 && type.value !== 'PROCESS') return t('This lot has no vessels');
    if (type.value === 'ANALYSIS' && metrics.value.length === 0) return t('Pick at least one reading');
    if (type.value === 'ADDITION') {
        if (product.value === null && freeName.value.trim() === '') return t('Pick a product or enter a name');
        if ((parseDecimal(dose.value) ?? 0) <= 0) return t('Enter a dose / quantity');
    }
    if (type.value === 'TOPPING' && (parseDecimal(liters.value) ?? 0) <= 0) return t('Enter liters');
    if (type.value === 'PROCESS' && kind.value.trim() === '') return t('Name the process (filtration, batonnage…)');

    return null;
}

/** Whole-cellar and vineyard plans always get a round (and a board card); one-lot plans only when named. */
function resolvedRoundName(): string | null {
    const named = roundName.value.trim();
    if (named !== '') return named;
    if (!cellarMode.value && type.value !== 'VINEYARD') return null;
    const base =
        type.value === 'ANALYSIS'
            ? metrics.value.map((m) => t(METRIC_LABELS[m])).join(' + ')
            : type.value === 'ADDITION'
              ? (product.value?.name ?? freeName.value.trim())
              : type.value === 'TOPPING'
                ? t('Topping')
                : kind.value.trim();

    return `${base} · ${dayLabel(date.value, props.locale)}`;
}

const success = ref<string | null>(null);

function submit(): void {
    clientError.value = validate();
    success.value = null;
    if (clientError.value !== null) return;
    const items = planned.value;
    if (items.length === 0) {
        clientError.value = t('Pick at least one vessel');

        return;
    }

    busy.value = true;
    router.post(
        '/cellar/activities',
        {
            round_name: resolvedRoundName(),
            assigned_to_id: assignee.value,
            activities: items.map((i, index) => ({
                type: i.type,
                planned_for: i.planned_for,
                wine_lot_id: i.wine_lot_id,
                vessel_id: i.vessel_id,
                note: note.value.trim() || null,
                sort_order: index,
                payload: i.payload as Record<string, never>,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                success.value = t('Planned :n tasks for :date', { n: items.length, date: dayLabel(date.value, props.locale) });
                selected.value = [];
                dose.value = '';
                liters.value = '';
                kind.value = '';
                note.value = '';
                roundName.value = '';
                parcelIds.value = [];
            },
            onFinish: () => (busy.value = false),
        },
    );
}

const DOSE_MODES = computed(() => [
    { value: 'per_hl', label: t('per hL') },
    { value: 'per_l', label: t('per L') },
    { value: 'per_vessel', label: t('per vessel') },
    { value: 'total', label: t('total (split by volume)') },
]);

const doseUnitLabel = computed(() => ({ per_hl: `${unit.value}/hL`, per_l: `${unit.value}/L`, per_vessel: unit.value, total: unit.value })[doseMode.value]);

function toggleIn<T>(list: T[], value: T): T[] {
    return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}

const assigneeName = computed(() => props.members.find((m) => m.value === assignee.value)?.label.split(' ')[0] ?? t('anyone'));
const targetCount = computed(() => planned.value.length);

const chip = (on: boolean) =>
    cn(
        'h-7 border px-2.5 text-xs transition-colors',
        on ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card text-foreground hover:border-foreground/40',
    );
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
            <div>
                <h3 class="text-sm font-semibold text-foreground">{{ t('Plan work') }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('The team confirms each task at the tank, on Today.') }}</p>
            </div>

            <div class="flex flex-wrap gap-1.5" role="radiogroup" :aria-label="t('Type')">
                <button v-for="k in ACTIVITY_TYPES" :key="k" type="button" role="radio" :aria-checked="type === k" :class="chip(type === k)" @click="type = k">
                    {{ t(ACTIVITY_LABELS[k]) }}
                </button>
            </div>

            <div v-if="usesScope" class="grid grid-cols-2 bg-muted p-1 text-xs">
                <button type="button" :class="cn('h-7', scope === 'lot' ? 'bg-background font-semibold' : 'text-muted-foreground')" @click="scope = 'lot'">
                    {{ t('One lot') }}
                </button>
                <button type="button" :class="cn('h-7', scope === 'cellar' ? 'bg-background font-semibold' : 'text-muted-foreground')" @click="scope = 'cellar'">
                    {{ t('Whole cellar · :n', { n: filledVessels.length }) }}
                </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <FormField v-slot="{ id, invalid }" v-if="needsLot" :label="t('Wine lot')">
                    <Combobox :id="id" :invalid="invalid" v-model="lotId" :options="lotOptions" :placeholder="t('Select lot…')" clearable />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Date')">
                    <Input :id="id" :invalid="invalid" v-model="date" type="date" />
                </FormField>
            </div>

            <div v-if="cellarMode" class="border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                {{ t('Pick vessels on the map →') }} <span class="font-medium text-foreground">{{ targets.length }}/{{ filledVessels.length }}</span>
            </div>

            <FormField
                v-if="!cellarMode && lot && type !== 'TRANSFER' && type !== 'VINEYARD'"
                :label="t('Vessels (none selected = all :n)', { n: lot.vessels.length })"
            >
                <div class="flex max-h-28 flex-wrap gap-1.5 overflow-y-auto">
                    <button v-for="v in lot.vessels" :key="v.id" type="button" :class="chip(selected.includes(v.id))" @click="selected = toggleIn(selected, v.id)">
                        {{ v.name }} · {{ qty(v.volume) }} L
                    </button>
                    <span v-if="lot.vessels.length === 0" class="text-xs text-muted-foreground">{{ t('This lot has no vessels') }}</span>
                </div>
            </FormField>

            <!-- Per type -->
            <template v-if="type === 'ADDITION'">
                <FormField v-slot="{ id, invalid }" :label="t('Product')">
                    <Combobox :id="id" :invalid="invalid" v-model="productId" :options="productOptions" :placeholder="t('Search product… (bentonite, SO₂, yeast)')" clearable />
                </FormField>
                <div v-if="productId === null" class="grid grid-cols-[1fr_80px] gap-2">
                    <FormField v-slot="{ id, invalid }" :label="t('Or a free name')" :hint="t('Not in stock — no stock is drawn.')">
                        <Input :id="id" :invalid="invalid" v-model="freeName" />
                    </FormField>
                    <FormField v-slot="{ id, invalid }" :label="t('Unit')">
                        <Input :id="id" :invalid="invalid" v-model="freeUnit" />
                    </FormField>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <FormField v-slot="{ id, invalid }" :label="t('Dose mode')">
                        <Select :id="id" :invalid="invalid" v-model="doseMode" :options="DOSE_MODES" />
                    </FormField>
                    <FormField v-slot="{ id, invalid }" :label="t('Dose (:unit)', { unit: doseUnitLabel })">
                        <Input :id="id" :invalid="invalid" v-model="dose" inputmode="decimal" />
                    </FormField>
                </div>
            </template>

            <FormField v-if="type === 'ANALYSIS'" :label="t('Readings')">
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="m in PLANNER_METRICS" :key="m" type="button" :class="chip(metrics.includes(m))" @click="metrics = toggleIn(metrics, m)">
                        {{ t(METRIC_LABELS[m]) }}
                    </button>
                </div>
            </FormField>

            <template v-if="type === 'TRANSFER' && lot">
                <div class="grid grid-cols-2 gap-2">
                    <FormField v-slot="{ id, invalid }" :label="t('From vessel')">
                        <Combobox :id="id" :invalid="invalid"
                            :empty-text="t('No vessel matches.')"
                            v-model="fromVessel"
                            :placeholder="t('Select…')"
                            :options="lot.vessels.map((v) => ({ value: v.id, label: `${v.name} · ${qty(v.volume)} L` }))"
                        />
                    </FormField>
                    <FormField v-slot="{ id, invalid }" :label="t('To vessel')">
                        <Combobox :id="id" :invalid="invalid" v-model="toVessel" :placeholder="t('Select…')" :options="allVesselOptions" :empty-text="t('No vessel matches.')" />
                    </FormField>
                </div>
                <FormField v-slot="{ id, invalid }" :label="t('Liters')">
                    <Input :id="id" :invalid="invalid" v-model="liters" inputmode="decimal" />
                </FormField>
            </template>

            <FormField v-slot="{ id, invalid }" v-if="type === 'PROCESS'" :label="t('Process kind')">
                <Input :id="id" :invalid="invalid" v-model="kind" :placeholder="t('filtration, batonnage, pump-over…')" />
            </FormField>

            <FormField v-slot="{ id, invalid }" v-if="type === 'TOPPING'" :label="t('Liters per vessel')">
                <Input :id="id" :invalid="invalid" v-model="liters" inputmode="decimal" />
            </FormField>

            <template v-if="type === 'VINEYARD'">
                <FormField v-slot="{ id, invalid }" :label="t('Task')">
                    <Input :id="id" :invalid="invalid" v-model="kind" :placeholder="t('pruning, spraying, mowing, canopy…')" />
                </FormField>
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center gap-3 text-sm font-medium">
                        {{ t('Parcels · :sel/:total', { sel: parcelIds.length, total: parcels.length }) }}
                        <button type="button" class="text-xs font-normal underline underline-offset-2" @click="parcelIds = parcels.map((p) => p.id)">{{ t('Select all') }}</button>
                        <button type="button" class="text-xs font-normal underline underline-offset-2" @click="parcelIds = []">{{ t('None') }}</button>
                    </div>
                    <div class="flex max-h-32 flex-wrap gap-1.5 overflow-y-auto">
                        <button v-for="p in parcels" :key="p.id" type="button" :class="chip(parcelIds.includes(p.id))" @click="parcelIds = toggleIn(parcelIds, p.id)">
                            {{ p.name }}<template v-if="p.grape_variety"> · {{ p.grape_variety }}</template><template v-if="p.area_hectares"> · {{ qty(p.area_hectares) }} ha</template>
                        </button>
                        <p v-if="parcels.length === 0" class="text-xs text-muted-foreground">{{ t('No parcels defined — add them in the Vineyards module.') }}</p>
                    </div>
                </div>
            </template>

            <FormField :label="t('Who')">
                <div class="flex flex-wrap gap-1.5">
                    <button type="button" :class="chip(assignee === null)" @click="assignee = null">{{ t('Anyone') }}</button>
                    <button v-for="m in members" :key="m.value" type="button" :class="cn(chip(assignee === m.value), 'inline-flex items-center gap-1.5')" @click="assignee = m.value">
                        <span class="inline-flex size-4 items-center justify-center rounded-full bg-muted text-[9px] text-foreground">{{ initials(m.label) }}</span>
                        {{ m.value === meId ? t('Me') : m.label.split(' ')[0] }}
                    </button>
                </div>
            </FormField>

            <div class="grid gap-3 sm:grid-cols-2">
                <FormField v-slot="{ id, invalid }" :label="t('Note for the team (optional)')">
                    <Input :id="id" :invalid="invalid" v-model="note" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Round name (auto if empty)')">
                    <Input :id="id" :invalid="invalid" v-model="roundName" />
                </FormField>
            </div>

            <div v-if="planned.length" class="border border-border">
                <p class="border-b border-border bg-muted px-3 py-1.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                    {{ t('Will create') }}
                </p>
                <ul class="max-h-40 overflow-y-auto text-xs">
                    <li v-for="(p, i) in planned" :key="i" class="flex justify-between gap-2 border-b border-border px-3 py-1 last:border-0">
                        <span class="truncate font-medium">{{ p.label }}</span>
                        <span class="shrink-0 tabular-nums text-muted-foreground">{{ p.detail }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="shrink-0 border-t border-border p-4">
            <p v-if="clientError" class="mb-2 text-xs text-destructive" role="alert">{{ clientError }}</p>
            <p v-if="success" class="mb-2 text-xs text-success">{{ success }}</p>
            <p class="mb-2 bg-muted px-3 py-2 text-xs text-muted-foreground">
                {{ t(ACTIVITY_LABELS[type]) }} ·
                {{ type === 'VINEYARD' ? t(':n parcels', { n: targetCount }) : t(':n vessels', { n: targetCount }) }} ·
                {{ dayLabel(date, locale) }} → {{ assigneeName }}
                <template v-if="targetCount > 0 && resolvedRoundName()"> · {{ t('board card auto-created') }}</template>
            </p>
            <Button class="w-full" size="lg" :disabled="busy" @click="submit">
                {{ targetCount > 0 ? t('Plan :n tasks', { n: targetCount }) : t('Plan it') }}
            </Button>
        </div>
    </div>
</template>
