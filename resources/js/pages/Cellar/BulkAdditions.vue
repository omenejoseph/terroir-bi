<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Check, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import CellarTabs from '@/components/cellar/CellarTabs.vue';
import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import Card from '@/components/ui/Card.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Select from '@/components/ui/Select.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { LOT_STATUS_LABELS, parseDecimal, qty } from '@/lib/cellar';
import { cn } from '@/lib/cn';
import { formatMoney, formatUnitPrice, unitPriceToMinor } from '@/lib/money';
import type { LotOption, ProductOption } from '@/types/cellar';
import type { SharedProps } from '@/types';
import type { ComboboxOption } from '@/types/ui';

/**
 * Cellar › Bulk additions — the same product into many lots at once. The
 * dose (per L or per hL, in mass or volume units) is converted into the
 * product's own unit for each lot's litres — or one vessel's, when the row
 * names a vessel. All-or-nothing on the server, and blocked when stock is
 * short.
 */
const props = defineProps<{ lots: LotOption[]; products: ProductOption[] }>();

const page = usePage<SharedProps>();
const { t } = useTranslations();
const { can } = useAuth();
const finance = computed(() => can('finance.view'));
const currency = computed(() => page.props.org?.default_currency ?? 'EUR');

const productId = ref<string | null>(null);
const product = computed(() => props.products.find((p) => p.id === productId.value) ?? null);
const name = ref('');
const category = ref('');
const unit = ref('g');
const dose = ref('');
const doseUnit = ref('g/hL');
const cost = ref('');
const note = ref('');

const productOptions = computed<ComboboxOption[]>(() =>
    props.products.map((p) => ({ value: p.id, label: p.name, description: `${p.category ?? ''} · ${t('stock')} ${qty(p.current_stock)} ${p.unit}`, keywords: [p.category ?? ''] })),
);
const DOSE_UNITS = ['g/L', 'g/hL', 'mg/L', 'mg/hL', 'mL/L', 'mL/hL', 'kg/hL'];

const MASS: Record<string, number> = { mg: 0.001, g: 1, kg: 1000 };
const VOLUME: Record<string, number> = { ml: 0.001, l: 1 };

/** The addition's unit: the product's, else the one typed. */
const targetUnit = computed(() => product.value?.unit ?? unit.value);

/** Converts `value` from one unit into another of the same kind; null when they don't convert. */
function convert(value: number, from: string, to: string): number | null {
    const f = from.toLowerCase();
    const tt = to.toLowerCase();
    if (f === tt) return value;
    if (MASS[f] !== undefined && MASS[tt] !== undefined) return (value * MASS[f]) / MASS[tt];
    if (VOLUME[f] !== undefined && VOLUME[tt] !== undefined) return (value * VOLUME[f]) / VOLUME[tt];

    return null;
}

const unitMismatch = computed(() => {
    const [num] = doseUnit.value.split('/');

    return convert(1, num!, targetUnit.value) === null;
});

// Rows
const statusFilter = ref('');
const grapeFilter = ref('');
const search = ref('');
const selected = ref<string[]>([]);
const vesselFor = reactive<Record<string, string>>({});
const override = reactive<Record<string, string>>({});

const grapes = computed(() => [...new Set(props.lots.map((l) => l.grape_variety).filter(Boolean))] as string[]);
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.lots.filter(
        (l) =>
            (statusFilter.value === '' || l.status === statusFilter.value) &&
            (grapeFilter.value === '' || l.grape_variety === grapeFilter.value) &&
            (q === '' || [l.name, l.lot_number, l.grape_variety ?? '', ...l.vessels.map((v) => v.name)].some((x) => x.toLowerCase().includes(q))),
    );
});

function litres(l: LotOption): number {
    const v = l.vessels.find((x) => x.id === vesselFor[l.id]);

    return Number(v ? v.volume : l.current_volume);
}

function amount(l: LotOption): number | null {
    const manual = parseDecimal(override[l.id]);
    if (manual !== null) return manual;
    const d = parseDecimal(dose.value);
    if (d === null) return null;
    const [num, per] = doseUnit.value.split('/');
    const raw = d * (per === 'hL' ? litres(l) / 100 : litres(l));
    const converted = convert(raw, num!, targetUnit.value);

    return converted === null ? null : Math.round(converted * 1000) / 1000;
}

const lines = computed(() => props.lots.filter((l) => selected.value.includes(l.id)).map((l) => ({ lot: l, quantity: amount(l) })));
const total = computed(() => lines.value.reduce((n, x) => n + (x.quantity ?? 0), 0));
const unitCost = computed(() => (product.value ? product.value.cost_per_unit : finance.value && parseDecimal(cost.value) !== null ? unitPriceToMinor(parseDecimal(cost.value) ?? 0) : null));
const totalCost = computed(() => (unitCost.value === null ? null : Math.round(unitCost.value * total.value)));
const short = computed(() => (product.value ? total.value - Number(product.value.current_stock) : 0));

const allShownSelected = computed(() => shown.value.length > 0 && shown.value.every((l) => selected.value.includes(l.id)));
function toggleAll(): void {
    selected.value = allShownSelected.value ? selected.value.filter((id) => !shown.value.some((l) => l.id === id)) : [...new Set([...selected.value, ...shown.value.map((l) => l.id)])];
}
function toggle(id: string): void {
    selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id];
}

const busy = ref(false);
const done = ref<{ name: string; lines: { lot: string; quantity: number; unit: string }[] } | null>(null);
const error = computed(() => {
    if (!product.value && name.value.trim() === '') return t('Pick a product or enter a name');
    if (unitMismatch.value) return t('The dose unit does not convert into :unit.', { unit: targetUnit.value });
    if (lines.value.length === 0) return t('Select at least one lot');
    if (lines.value.some((x) => x.quantity === null || x.quantity <= 0)) return t('Enter a dose / quantity');

    return null;
});

function apply(): void {
    if (error.value) return;
    busy.value = true;
    const snapshot = lines.value.map((x) => ({ lot: x.lot.name, quantity: x.quantity ?? 0, unit: targetUnit.value }));
    router.post(
        '/cellar/bulk-additions',
        {
            name: product.value?.name ?? name.value.trim(),
            category: product.value?.category ?? (category.value || null),
            unit: targetUnit.value,
            enological_product_id: product.value?.id ?? null,
            cost_per_unit: product.value ? null : unitCost.value,
            note: note.value.trim() || null,
            lots: lines.value.map((x) => ({ wine_lot_id: x.lot.id, quantity: x.quantity, vessel_id: vesselFor[x.lot.id] || null })),
        } as never,
        {
            preserveScroll: true,
            onSuccess: () => {
                done.value = { name: product.value?.name ?? name.value, lines: snapshot };
                selected.value = [];
            },
            onFinish: () => (busy.value = false),
        },
    );
}

const STATUS = computed(() => [{ value: '', label: t('All statuses') }, ...['FERMENTING', 'AGING', 'READY'].map((s) => ({ value: s, label: t(LOT_STATUS_LABELS[s]!) }))]);
</script>

<template>
    <AppLayout :title="t('Bulk additions')">
        <div class="flex flex-col gap-5">
            <PageHeader :title="t('Bulk additions')" :description="t('Add the same product to multiple lots')">
                <template #actions><Button size="sm" variant="outline" href="/cellar/bulk-analyses">{{ t('Bulk analyses') }}</Button></template>
            </PageHeader>
            <CellarTabs current="lots" />

            <Card v-if="done" class="flex flex-col items-center gap-3 p-8 text-center">
                <Check class="size-10 text-success" />
                <h3 class="text-lg font-semibold">{{ t(':name added to :n lots', { name: done.name, n: done.lines.length }) }}</h3>
                <table class="w-full max-w-md text-left text-xs">
                    <tbody>
                        <tr v-for="(l, i) in done.lines" :key="i" class="border-t border-border"><td class="py-1.5">{{ l.lot }}</td><td class="py-1.5 text-right tabular-nums">{{ qty(l.quantity) }} {{ l.unit }}</td></tr>
                    </tbody>
                </table>
                <div class="flex gap-2"><Button variant="outline" href="/cellar">{{ t('Back to cellar') }}</Button><Button @click="done = null">{{ t('Add another') }}</Button></div>
            </Card>

            <template v-else>
                <Card class="grid gap-4 p-4 sm:grid-cols-3">
                    <FormField v-slot="{ id }" :label="t('Product from stock')" class="sm:col-span-2">
                        <Combobox :id="id" v-model="productId" :options="productOptions" :placeholder="t('Search product…')" clearable />
                    </FormField>
                    <FormField v-slot="{ id }" :label="t('Dose')">
                        <div class="flex gap-2">
                            <Input :id="id" v-model="dose" inputmode="decimal" class="w-24" />
                            <Select v-model="doseUnit" :options="DOSE_UNITS.map((u) => ({ value: u, label: u }))" class="flex-1" />
                        </div>
                    </FormField>
                    <template v-if="!product">
                        <FormField v-slot="{ id }" :label="t('Name')"><Input :id="id" v-model="name" /></FormField>
                        <FormField v-slot="{ id }" :label="t('Category')"><Input :id="id" v-model="category" /></FormField>
                        <FormField v-slot="{ id }" :label="t('Unit')"><Input :id="id" v-model="unit" /></FormField>
                        <FormField v-if="finance" v-slot="{ id }" :label="t('Cost per unit (€)')"><Input :id="id" v-model="cost" inputmode="decimal" /></FormField>
                    </template>
                    <p v-else class="text-xs text-muted-foreground sm:col-span-3">
                        {{ product.category }} · {{ t('Stock') }}: {{ qty(product.current_stock) }} {{ product.unit }}
                        <template v-if="finance && product.cost_per_unit !== null"> · {{ formatUnitPrice(product.cost_per_unit, currency) }}/{{ product.unit }}</template>
                    </p>
                    <FormField v-slot="{ id }" :label="t('Note')" class="sm:col-span-3"><Input :id="id" v-model="note" /></FormField>
                </Card>

                <Card class="flex flex-col">
                    <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                        <Select v-model="statusFilter" :options="STATUS" class="w-36" />
                        <Select v-model="grapeFilter" :options="[{ value: '', label: t('All grapes') }, ...grapes.map((g) => ({ value: g, label: g }))]" class="w-40" />
                        <div class="relative min-w-40 flex-1">
                            <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="search" class="pl-8" :placeholder="t('Search lots...')" />
                        </div>
                        <Button size="sm" variant="outline" @click="toggleAll">{{ allShownSelected ? t('Deselect all') : t('Select all') }}</Button>
                    </div>
                    <div class="max-h-[55vh] overflow-auto">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-muted text-[10px] uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="w-8 px-3 py-2" />
                                    <th class="px-2 py-2 text-left font-medium">{{ t('Lot') }}</th>
                                    <th class="px-2 py-2 text-left font-medium">{{ t('Vessel') }}</th>
                                    <th class="px-2 py-2 text-right font-medium">L</th>
                                    <th class="px-2 py-2 text-right font-medium">{{ t('Amount') }} ({{ targetUnit }})</th>
                                    <th v-if="finance" class="px-2 py-2 text-right font-medium">{{ t('Cost') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in shown" :key="l.id" :class="cn('border-t border-border', selected.includes(l.id) && 'bg-primary/5')">
                                    <td class="px-3 py-1.5"><input type="checkbox" class="size-4 accent-primary" :checked="selected.includes(l.id)" :aria-label="l.name" @change="toggle(l.id)" /></td>
                                    <td class="px-2 py-1.5"><span class="font-medium">{{ l.name }}</span> <span class="text-muted-foreground">{{ [l.grape_variety, l.vintage].filter(Boolean).join(' ') }}</span></td>
                                    <td class="px-2 py-1">
                                        <Select
                                            :model-value="vesselFor[l.id] ?? ''"
                                            :options="[{ value: '', label: t('Whole lot') }, ...l.vessels.map((v) => ({ value: v.id, label: `${v.name} · ${qty(v.volume)} L` }))]"
                                            class="w-44"
                                            @update:model-value="vesselFor[l.id] = $event"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ qty(litres(l)) }}</td>
                                    <td class="px-2 py-1">
                                        <Input v-model="override[l.id]" inputmode="decimal" class="ml-auto h-7 w-24 text-right" :placeholder="amount(l) === null ? '' : qty(amount(l))" :aria-label="t('Amount for :lot', { lot: l.name })" />
                                    </td>
                                    <td v-if="finance" class="px-2 py-1.5 text-right tabular-nums">{{ unitCost !== null && amount(l) !== null ? formatMoney(Math.round(unitCost * (amount(l) ?? 0)), currency) : '—' }}</td>
                                </tr>
                                <tr v-if="shown.length === 0"><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">{{ t('No lots match the filters') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card class="flex flex-col gap-3 p-4">
                    <div class="grid grid-cols-3 gap-3">
                        <div><p class="text-[10px] uppercase text-muted-foreground">{{ t('Lots') }}</p><p class="text-2xl font-semibold">{{ lines.length }}</p></div>
                        <div><p class="text-[10px] uppercase text-muted-foreground">{{ t('Total') }}</p><p class="text-2xl font-semibold tabular-nums">{{ qty(total) }} {{ targetUnit }}</p></div>
                        <div v-if="finance"><p class="text-[10px] uppercase text-muted-foreground">{{ t('Cost') }}</p><p class="text-2xl font-semibold tabular-nums">{{ totalCost === null ? '—' : formatMoney(totalCost, currency) }}</p></div>
                    </div>
                    <Callout v-if="short > 0" tone="warning" :title="t('Not enough stock')">{{ t('Need :need :unit, available :have :unit.', { need: qty(total), have: qty(product?.current_stock ?? 0), unit: targetUnit }) }}</Callout>
                    <p v-if="error && lines.length" class="text-xs text-destructive">{{ error }}</p>
                    <Button v-if="can('cellar.manage')" class="self-end" :disabled="busy || error !== null || short > 0" @click="apply">{{ t('Apply to :n lots', { n: lines.length }) }}</Button>
                </Card>
            </template>
        </div>
    </AppLayout>
</template>
