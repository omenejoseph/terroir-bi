<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { KitchenItem, KitchenOptions } from '@/types/kitchen';

/**
 * Add / edit a kitchen item. Stock is set once, as the opening figure on a new item; after that it
 * moves only through Adjust stock, so every change is in the item's history.
 */
const props = defineProps<{ open: boolean; item: KitchenItem | null; options: KitchenOptions }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();
const isEdit = computed(() => props.item !== null);

const form = useForm({
    name: '',
    category: 'RAW_MATERIAL',
    group: '',
    unit: 'kg',
    location: 'restaurant',
    min_stock: '',
    cost_per_unit: '',
    supplier: '',
    is_active: true,
    opening_stock: '',
});

watch(
    () => [props.open, props.item?.id],
    () => {
        if (!props.open) return;

        const i = props.item;
        form.defaults({
            name: i?.name ?? '',
            category: i?.category ?? props.options.categories[0]?.value ?? 'RAW_MATERIAL',
            group: i?.group ?? '',
            unit: i?.unit ?? props.options.units[0]?.value ?? 'kg',
            location: i?.location ?? props.options.locations[0]?.value ?? 'restaurant',
            min_stock: i?.min_stock !== null && i?.min_stock !== undefined ? String(Number(i.min_stock)) : '',
            cost_per_unit: i?.cost_per_unit ? String(i.cost_per_unit.minor / 100) : '',
            supplier: i?.supplier ?? '',
            is_active: i?.is_active ?? true,
            opening_stock: '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function num(value: string): number | null {
    const n = Number.parseFloat(value.replace(',', '.'));

    return value.trim() === '' || !Number.isFinite(n) ? null : n;
}

function blank(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    form
        .transform((d) => ({
            name: d.name.trim(),
            category: d.category,
            group: blank(d.group),
            unit: d.unit,
            location: d.location,
            min_stock: num(d.min_stock),
            cost_per_unit: num(d.cost_per_unit) === null ? null : Math.round((num(d.cost_per_unit) ?? 0) * 100),
            supplier: blank(d.supplier),
            is_active: d.is_active,
            ...(isEdit.value ? {} : { opening_stock: num(d.opening_stock) }),
        }))
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/kitchen/items/${props.item!.id}` : '/kitchen/items', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit item') : t('New item')" @close="emit('close')">
        <form id="kitchen-item-form" class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name">
                <Input :id="id" v-model="form.name" :invalid="invalid" maxlength="255" />
            </FormField>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField v-slot="{ id }" :label="t('Category')" required :error="form.errors.category">
                    <Select :id="id" v-model="form.category" :options="options.categories" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Group')" :error="form.errors.group">
                    <Input :id="id" v-model="form.group" :invalid="invalid" maxlength="255" :placeholder="t('Optional')" />
                </FormField>
                <FormField v-slot="{ id }" :label="t('Unit')" required :error="form.errors.unit">
                    <Select :id="id" v-model="form.unit" :options="options.units" />
                </FormField>
                <FormField v-slot="{ id }" :label="t('Location')" required :error="form.errors.location">
                    <Select :id="id" v-model="form.location" :options="options.locations" />
                </FormField>
                <FormField v-if="!isEdit" v-slot="{ id, invalid }" :label="t('Opening stock')" :error="form.errors.opening_stock">
                    <Input :id="id" v-model="form.opening_stock" :invalid="invalid" type="number" min="0" step="0.001" inputmode="decimal" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Minimum stock')" :hint="t('Highlighted when stock falls below this.')" :error="form.errors.min_stock">
                    <Input :id="id" v-model="form.min_stock" :invalid="invalid" type="number" min="0" step="0.001" inputmode="decimal" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Cost per unit')" :error="form.errors.cost_per_unit">
                    <Input :id="id" v-model="form.cost_per_unit" :invalid="invalid" type="number" min="0" step="0.01" inputmode="decimal" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Supplier')" :error="form.errors.supplier">
                    <Input :id="id" v-model="form.supplier" :invalid="invalid" maxlength="255" :placeholder="t('Optional')" />
                </FormField>
            </div>

            <SwitchRow v-if="isEdit" v-model="form.is_active" :label="t('Active')" :hint="t('Inactive items are left out of new stock-takes.')" />
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button type="submit" form="kitchen-item-form" :disabled="form.processing || form.name.trim() === ''">
                {{ isEdit ? t('Save changes') : t('Add item') }}
            </Button>
        </template>
    </SidePanel>
</template>
