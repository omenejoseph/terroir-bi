<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import { parseDecimal, qty } from '@/lib/cellar';
import type { LotRef } from '@/types/cellar';
import type { ComboboxOption } from '@/types/ui';

/**
 * Bottle (part of) the lot into finished stock — an existing inventory item,
 * a new one created on the spot, or none. The wine is drawn from the lot's
 * vessels and comes back to them if the bottling is reversed.
 */
const props = defineProps<{ open: boolean; lot: LotRef; inventoryItems?: ComboboxOption[] | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();

const page = usePage();
const { t } = useTranslations();
const { can } = useAuth();

const target = ref<'existing' | 'new' | 'none'>('existing');
const form = useForm({
    bottle_count: '',
    bottle_volume_ml: '750',
    date: '',
    inventory_item_id: null as string | null,
    note: '',
    new_name: '',
    new_sku: '',
    new_sales_unit: 'bottles',
    new_per_case: '6',
    new_price: '',
});

// Finished items come from the caller when it has them (the Record drawer); on the lot page they're its lazy prop.
const items = computed<ComboboxOption[]>(() => props.inventoryItems ?? (page.props.inventoryItems as ComboboxOption[] | undefined) ?? []);

watch(() => props.open, (open) => {
    if (!open) return;
    form.defaults({ ...form.data(), bottle_count: '', date: new Date().toISOString().slice(0, 10), inventory_item_id: null, note: '', new_name: `${props.lot.name} ${props.lot.vintage ?? ''}`.trim(), new_sku: '', new_price: '' });
    form.reset();
    form.clearErrors();
    if (!props.inventoryItems && page.props.inventoryItems === undefined) router.reload({ only: ['inventoryItems'] });
});

const litres = computed(() => ((parseDecimal(form.bottle_count) ?? 0) * (Number(form.bottle_volume_ml) || 0)) / 1000);
const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function submit(): void {
    form.transform((d) => ({
        bottle_count: Number(d.bottle_count) || null,
        bottle_volume_ml: Number(d.bottle_volume_ml) || 750,
        date: d.date || null,
        note: d.note || null,
        inventory_item_id: target.value === 'existing' ? d.inventory_item_id : null,
        new_item:
            target.value === 'new'
                ? {
                      name: d.new_name,
                      sku: d.new_sku,
                      sales_unit: d.new_sales_unit,
                      bottles_per_case: Number(d.new_per_case) || 1,
                      default_price: can('finance.view') && parseDecimal(d.new_price) !== null ? Math.round((parseDecimal(d.new_price) ?? 0) * 100) : null,
                  }
                : null,
    }));
    form.post(`/cellar/lots/${props.lot.id}/bottlings`, { preserveScroll: true, onSuccess: () => { emit('saved'); emit('close'); } });
}
</script>

<template>
    <SidePanel :open="open" :title="t('Bottle')" :subtitle="lot.name" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Bottles')" required :error="err('bottle_count')"><Input :id="id" :invalid="invalid" v-model="form.bottle_count" inputmode="numeric" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Bottle size (ml)')" :error="err('bottle_volume_ml')">
                    <Select :id="id" :invalid="invalid" v-model="form.bottle_volume_ml" :options="['187', '375', '500', '750', '1000', '1500', '3000'].map((v) => ({ value: v, label: `${v} ml` }))" />
                </FormField>
            </FieldRow>
            <p class="text-xs text-muted-foreground">{{ t('Uses :n L of :have L.', { n: qty(litres), have: qty(lot.current_volume) }) }}</p>
            <FormField v-slot="{ id, invalid }" :label="t('Date')" :error="form.errors.date"><Input :id="id" :invalid="invalid" v-model="form.date" type="date" /></FormField>
            <FormField :label="t('Into stock')">
                <Tabs
                    :items="[
                        { label: t('Existing item'), value: 'existing' },
                        { label: t('New item'), value: 'new' },
                        { label: t('None'), value: 'none' },
                    ]"
                    :current="target"
                    full
                    @select="target = $event as 'existing' | 'new' | 'none'"
                />
            </FormField>
            <FormField v-slot="{ id, invalid }" v-if="target === 'existing'" :label="t('Inventory item')" :error="err('inventory_item_id')">
                <Combobox :id="id" :invalid="invalid" v-model="form.inventory_item_id" :options="items" :placeholder="t('Search finished goods…')" clearable />
            </FormField>
            <template v-if="target === 'new'">
                <FieldRow>
                    <FormField v-slot="{ id, invalid }" :label="t('Item name')" required :error="err('new_item.name')"><Input :id="id" :invalid="invalid" v-model="form.new_name" /></FormField>
                    <FormField v-slot="{ id, invalid }" :label="t('SKU')" required :error="err('new_item.sku')"><Input :id="id" :invalid="invalid" v-model="form.new_sku" /></FormField>
                </FieldRow>
                <FieldRow>
                    <FormField v-slot="{ id, invalid }" :label="t('Sold in')"><Select :id="id" :invalid="invalid" v-model="form.new_sales_unit" :options="[{ value: 'bottles', label: t('Bottles') }, { value: 'cases', label: t('Cases') }]" /></FormField>
                    <FormField v-slot="{ id, invalid }" :label="t('Bottles per case')"><Input :id="id" :invalid="invalid" v-model="form.new_per_case" inputmode="numeric" /></FormField>
                </FieldRow>
                <FormField v-slot="{ id, invalid }" v-if="can('finance.view')" :label="t('Price (€)')" :error="err('new_item.default_price')"><Input :id="id" :invalid="invalid" v-model="form.new_price" inputmode="decimal" /></FormField>
            </template>
            <FormField v-slot="{ id, invalid }" :label="t('Note')" :error="form.errors.note"><Textarea :id="id" :invalid="invalid" v-model="form.note" :rows="2" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Bottle') }}</Button>
        </template>
    </SidePanel>
</template>
