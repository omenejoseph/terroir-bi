<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { parseDecimal, qty } from '@/lib/cellar';
import { unitPriceToMinor } from '@/lib/money';
import type { LotRef, ProductOption, WineLotDetail } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * Record or correct an addition — from stock (a product: unit and cost come
 * with it, and stock is drawn) or by a free name. A dose per hL fills the
 * quantity from the litres in the chosen vessel (or the lot). Stock that
 * would go negative is refused by the server; the warning here says so first.
 */
type Addition = WineLotDetail['additions'][number] & { enological_product_id?: string | null };

const props = defineProps<{ open: boolean; lot: LotRef; products: ProductOption[]; record: Addition | null; vesselId?: string | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();

const { t } = useTranslations();
const { can } = useAuth();
const finance = computed(() => can('finance.view'));

const doseHl = ref('');
/** The prototype's category presets; free text is still allowed. */
const CATEGORIES = ['SO2', 'Yeast', 'Nutrient', 'Bentonite', 'Enzyme', 'Tannin', 'Acid', 'Fining', 'Sugar', 'Other'];
const form = useForm({ enological_product_id: null as string | null, name: '', category: '', quantity: '', unit: 'g', cost: '', vessel_id: '', note: '' });

watch(
    () => [props.open, props.record?.id],
    () => {
        if (!props.open) return;
        const r = props.record;
        form.defaults({
            enological_product_id: r?.enological_product_id ?? null,
            name: r?.name ?? '',
            category: r?.category ?? '',
            quantity: r?.quantity ?? '',
            unit: r?.unit ?? 'g',
            cost: '',
            vessel_id: r?.vessel_id ?? props.vesselId ?? '',
            note: r?.note ?? '',
        });
        form.reset();
        form.clearErrors();
        doseHl.value = '';
    },
    { immediate: true },
);

const product = computed(() => props.products.find((p) => p.id === form.enological_product_id) ?? null);
watch(product, (p) => {
    if (!p) return;
    form.name = p.name;
    form.unit = p.unit;
    form.category = p.category ?? '';
});

const productOptions = computed<ComboboxOption[]>(() =>
    props.products.map((p) => ({ value: p.id, label: p.name, description: `${p.category ?? ''} · ${t('stock')} ${qty(p.current_stock)} ${p.unit}`, keywords: [p.category ?? ''] })),
);
const vesselOptions = computed(() => [{ value: '', label: t('Whole lot') }, ...props.lot.vessels.map((v) => ({ value: v.vessel_id, label: `${v.vessel_name ?? '—'} · ${qty(v.volume)} L` }))]);

const liters = computed(() => {
    const v = props.lot.vessels.find((x) => x.vessel_id === form.vessel_id);

    return Number(v ? v.volume : props.lot.current_volume);
});

function fromDose(): void {
    const d = parseDecimal(doseHl.value);
    if (d !== null) form.quantity = String(Math.round(((d * liters.value) / 100) * 100) / 100);
}

/** What stock would be left — editing an addition gives its own quantity back first. */
const stockAfter = computed(() => {
    if (!product.value) return null;
    const q = parseDecimal(form.quantity) ?? 0;
    const giveBack = props.record && props.record.enological_product_id === product.value.id ? Number(props.record.quantity) : 0;

    return Number(product.value.current_stock) + giveBack - q;
});

const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function submit(): void {
    form.transform((d) => ({
        enological_product_id: d.enological_product_id,
        name: d.name,
        category: d.category || null,
        quantity: parseDecimal(d.quantity),
        unit: d.unit,
        vessel_id: d.vessel_id || null,
        note: d.note || null,
        ...(finance.value && !d.enological_product_id && parseDecimal(d.cost) !== null ? { cost_per_unit: unitPriceToMinor(parseDecimal(d.cost) ?? 0) } : {}),
    }));
    const url = `/cellar/lots/${props.lot.id}/additions`;
    if (props.record) form.patch(`${url}/${props.record.id}`, { preserveScroll: true, onSuccess: () => { emit('saved'); emit('close'); } });
    else form.post(url, { preserveScroll: true, onSuccess: () => { emit('saved'); emit('close'); } });
}
</script>

<template>
    <SidePanel :open="open" :title="record ? t('Edit addition') : t('New addition')" :subtitle="lot.name" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FormField v-slot="{ id, invalid }" :label="t('Product from stock')" :error="err('enological_product_id')">
                <Combobox :id="id" :invalid="invalid" v-model="form.enological_product_id" :options="productOptions" :placeholder="t('Search product…')" clearable />
            </FormField>
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name"><Input :id="id" :invalid="invalid" v-model="form.name" :disabled="product !== null" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Category')" :error="form.errors.category"><Input :id="id" :invalid="invalid" v-model="form.category" :disabled="product !== null" list="addition-categories" />
                    <datalist id="addition-categories"><option v-for="c in CATEGORIES" :key="c" :value="c" /></datalist>
                </FormField>
            </FieldRow>
            <FormField v-slot="{ id, invalid }" :label="t('Vessel')" :error="form.errors.vessel_id"><Select :id="id" :invalid="invalid" v-model="form.vessel_id" :options="vesselOptions" /></FormField>
            <div class="grid grid-cols-3 gap-3">
                <FormField v-slot="{ id, invalid }" :label="t('Dose / hL')" :hint="t(':n L', { n: qty(liters) })"><Input :id="id" :invalid="invalid" v-model="doseHl" inputmode="decimal" @input="fromDose" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Quantity')" required :error="err('quantity')"><Input :id="id" :invalid="invalid" v-model="form.quantity" inputmode="decimal" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Unit')" required :error="form.errors.unit"><Input :id="id" :invalid="invalid" v-model="form.unit" :disabled="product !== null" /></FormField>
            </div>
            <FormField v-slot="{ id, invalid }" v-if="finance && !product" :label="t('Cost per unit (€)')" :error="err('cost_per_unit')"><Input :id="id" :invalid="invalid" v-model="form.cost" inputmode="decimal" /></FormField>
            <Callout v-if="stockAfter !== null && stockAfter < 0" tone="warning" :title="t('Not enough stock')">
                {{ t('Only :n :unit in stock.', { n: qty(product?.current_stock ?? 0), unit: product?.unit ?? '' }) }}
            </Callout>
            <p v-else-if="stockAfter !== null" class="text-xs text-muted-foreground">{{ t('Stock after: :n :unit', { n: qty(stockAfter), unit: product?.unit ?? '' }) }}</p>
            <FormField v-slot="{ id, invalid }" :label="t('Note')" :error="form.errors.note"><Textarea :id="id" :invalid="invalid" v-model="form.note" :rows="2" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
