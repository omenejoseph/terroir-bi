<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { parseDecimal, qty } from '@/lib/cellar';
import { unitPriceToMinor } from '@/lib/money';
import type { EnologicalProduct } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * New / edit enological product. Cost can be typed per unit or worked out
 * from a package price and size. SO₂ products carry an uplift — the mg/L one
 * unit raises in one litre — with a helper that computes it from the SO₂
 * content (1 g of K₂S₂O₅ at 57 % SO₂ in 1 L adds 570 mg/L). Opening stock
 * is set on create only; afterwards stock moves through adjustments.
 */
const props = defineProps<{ open: boolean; product: EnologicalProduct | null; suppliers: ComboboxOption[] }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();
const { can } = useAuth();
const finance = computed(() => can('finance.view'));

const CATEGORIES = ['SO2', 'YEAST', 'NUTRIENT', 'FINING', 'ENZYME', 'TANNIN', 'ACID', 'BACTERIA', 'CLEANING', 'OTHER'];

const form = useForm({
    name: '',
    category: 'OTHER',
    unit: 'g',
    current_stock: '',
    min_stock: '',
    cost: '',
    manufacturer: '',
    packaging_size: '',
    supplier_id: null as string | null,
    so2_uplift_per_unit: '',
    is_active: true,
    notes: '',
});
const packPrice = ref('');
const packQty = ref('');
const so2Percent = ref('');

watch(
    () => [props.open, props.product?.id],
    () => {
        if (!props.open) return;
        const p = props.product;
        form.defaults({
            name: p?.name ?? '',
            category: p?.category ?? 'OTHER',
            unit: p?.unit ?? 'g',
            current_stock: '',
            min_stock: p?.min_stock ?? '',
            cost: p?.cost_per_unit ? String(p.cost_per_unit.minor / 100) : '',
            manufacturer: p?.manufacturer ?? '',
            packaging_size: p?.packaging_size ?? '',
            supplier_id: p?.supplier_id ?? null,
            so2_uplift_per_unit: p?.so2_uplift_per_unit ?? '',
            is_active: p?.is_active ?? true,
            notes: p?.notes ?? '',
        });
        form.reset();
        form.clearErrors();
        packPrice.value = '';
        packQty.value = '';
        so2Percent.value = '';
    },
    { immediate: true },
);

function fromPackage(): void {
    const price = parseDecimal(packPrice.value);
    const size = parseDecimal(packQty.value);
    if (price !== null && size !== null && size > 0) form.cost = String(Math.round((price / size) * 10000) / 10000);
}

function fromPercent(): void {
    const pct = parseDecimal(so2Percent.value);
    if (pct !== null) form.so2_uplift_per_unit = String(Math.round(pct * 10 * 100) / 100);
}

const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function submit(): void {
    form.transform((d) => ({
        name: d.name,
        category: d.category,
        unit: d.unit,
        min_stock: parseDecimal(d.min_stock),
        manufacturer: d.manufacturer || null,
        packaging_size: d.packaging_size || null,
        supplier_id: d.supplier_id,
        so2_uplift_per_unit: parseDecimal(d.so2_uplift_per_unit),
        is_active: d.is_active,
        notes: d.notes || null,
        ...(finance.value ? { cost_per_unit: parseDecimal(d.cost) === null ? null : unitPriceToMinor(parseDecimal(d.cost) ?? 0) } : {}),
        ...(props.product ? {} : { current_stock: parseDecimal(d.current_stock) ?? 0 }),
    }));
    if (props.product) form.patch(`/cellar/products/${props.product.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
    else form.post('/cellar/products', { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="product ? t('Edit product') : t('New product')" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name"><Input :id="id" v-model="form.name" :invalid="invalid" /></FormField>
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Category')" required :error="form.errors.category">
                    <Input :id="id" v-model="form.category" :invalid="invalid" list="product-categories" />
                    <datalist id="product-categories"><option v-for="c in CATEGORIES" :key="c" :value="c" /></datalist>
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Unit')" required :error="form.errors.unit"><Input :id="id" v-model="form.unit" :invalid="invalid" placeholder="g, kg, mL, L" /></FormField>
            </FieldRow>
            <FieldRow>
                <FormField v-if="!product" v-slot="{ id, invalid }" :label="t('Opening stock')" :error="err('current_stock')"><Input :id="id" v-model="form.current_stock" inputmode="decimal" :invalid="invalid" /></FormField>
                <FormField v-else :label="t('Stock')"><p class="py-2 text-sm">{{ qty(product.current_stock) }} {{ product.unit }}</p></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Minimum stock')" :error="err('min_stock')"><Input :id="id" v-model="form.min_stock" inputmode="decimal" :invalid="invalid" /></FormField>
            </FieldRow>
            <template v-if="finance">
                <FormField v-slot="{ id, invalid }" :label="t('Cost per :unit (€)', { unit: form.unit || t('unit') })" :error="err('cost_per_unit')"><Input :id="id" v-model="form.cost" inputmode="decimal" :invalid="invalid" /></FormField>
                <div class="grid grid-cols-[1fr_1fr_auto] items-end gap-2 bg-muted p-2">
                    <FormField v-slot="{ id }" :label="t('Package price (€)')"><Input :id="id" v-model="packPrice" inputmode="decimal" /></FormField>
                    <FormField v-slot="{ id }" :label="t('Package size (:unit)', { unit: form.unit })"><Input :id="id" v-model="packQty" inputmode="decimal" /></FormField>
                    <Button size="sm" variant="outline" @click="fromPackage">{{ t('Use') }}</Button>
                </div>
            </template>
            <FieldRow>
                <FormField v-slot="{ id }" :label="t('Manufacturer')"><Input :id="id" v-model="form.manufacturer" /></FormField>
                <FormField v-slot="{ id }" :label="t('Packaging')"><Input :id="id" v-model="form.packaging_size" :placeholder="t('e.g. 1 kg bag')" /></FormField>
            </FieldRow>
            <FormField v-slot="{ id, invalid }" :label="t('Supplier')" :error="err('supplier_id')"><Combobox :id="id" v-model="form.supplier_id" :options="suppliers" :invalid="invalid" :placeholder="t('Select supplier…')" clearable /></FormField>
            <FormField v-slot="{ id, invalid }" :label="t('SO₂ uplift (mg/L per :unit in 1 L)', { unit: form.unit || t('unit') })" :hint="t('e.g. 570 for K₂S₂O₅ in grams')" :error="err('so2_uplift_per_unit')">
                <Input :id="id" v-model="form.so2_uplift_per_unit" inputmode="decimal" :invalid="invalid" />
            </FormField>
            <div class="grid grid-cols-[1fr_auto] items-end gap-2 bg-muted p-2">
                <FormField v-slot="{ id }" :label="t('Don’t know? SO₂ content (%)')" :hint="t('K₂S₂O₅ 57 %, liquid solutions as labelled')"><Input :id="id" v-model="so2Percent" inputmode="decimal" /></FormField>
                <Button size="sm" variant="outline" class="mb-5" @click="fromPercent">{{ t('Compute') }}</Button>
            </div>
            <SwitchRow v-model="form.is_active" :label="t('Active')" :hint="t('Inactive products are hidden from pickers.')" />
            <FormField v-slot="{ id }" :label="t('Notes')"><Textarea :id="id" v-model="form.notes" :rows="2" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
