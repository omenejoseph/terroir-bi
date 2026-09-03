<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Separator from '@/components/ui/Separator.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { InventoryItem } from '@/types/inventory';

/**
 * New / Edit item (Figma `317:468`, and `322:704` with Advanced open — the
 * design only draws creation; editing reuses the same fields against
 * UpdateInventoryItemRequest, the same way TaskPanel and CustomerFormPanel are
 * one component for both).
 *
 * Naming note: the design's "Type" is our `category` (the InventoryCategory
 * enum) and the design's "Category" is our free-text `group`.
 */
const props = defineProps<{ open: boolean; item: InventoryItem | null }>();
const emit = defineEmits<{ close: [] }>();
const { t } = useTranslations();

const isEdit = computed(() => props.item !== null);

const form = useForm({
    name: '',
    sku: '',
    category: 'FINISHED',
    group: '',
    unit_size: '',
    unit: 'bottles',
    default_price: '',
    subcategory: '',
    vintage: '',
    min_stock: '',
    cost_per_unit: '',
    is_for_sale: false,
    sales_unit: 'bottles',
    description: '',
});

const TYPES = [
    { value: 'FINISHED', label: t('Finished') },
    { value: 'SEMI_FINISHED', label: t('Semi-Finished') },
    { value: 'RAW_MATERIAL', label: t('Raw Materials') },
];

const UNITS = [
    { value: 'bottles', label: t('Bottles') },
    { value: 'cases', label: t('Cases') },
    { value: 'liters', label: t('Litres') },
    { value: 'kg', label: t('Kilograms') },
    { value: 'units', label: t('Units') },
];

/** sales_unit only applies to packaged goods — the same rule the request enforces. */
const SALES_UNITS = [
    { value: 'bottles', label: t('Bottles') },
    { value: 'cases', label: t('Cases') },
];

const isPackaged = computed(() => ['bottles', 'cases'].includes(form.unit));

/** Money crosses the wire as integer minor units; the field takes major units. */
function toMinor(value: string): number | null {
    if (value.trim() === '') return null;

    const n = Number.parseFloat(value.replace(',', '.'));

    return Number.isFinite(n) ? Math.round(n * 100) : null;
}

const advancedSummary = computed(() => {
    const unit = UNITS.find((u) => u.value === form.unit)?.label ?? form.unit;

    return [
        unit,
        form.min_stock === '' ? t('No stock alert') : t('Alerts below :value', { value: form.min_stock }),
        form.is_for_sale ? t('For sale') : t('Not for sale'),
    ].join(' · ');
});

/** Repopulate fresh on every open — a cancelled edit must not linger into the next one. */
watch(
    () => [props.open, props.item?.id],
    () => {
        if (!props.open) return;

        const i = props.item;

        form.defaults({
            name: i?.name ?? '',
            sku: i?.sku ?? '',
            category: i?.category ?? 'FINISHED',
            group: i?.group ?? '',
            unit_size: i?.unit_size ?? '',
            unit: i?.unit ?? 'bottles',
            default_price: i?.default_price ? String(i.default_price.minor / 100) : '',
            subcategory: i?.subcategory ?? '',
            vintage: i?.vintage === null || i?.vintage === undefined ? '' : String(i.vintage),
            min_stock: i?.min_stock ?? '',
            cost_per_unit: i?.cost_per_unit ? String(i.cost_per_unit.minor / 100) : '',
            is_for_sale: i?.is_for_sale ?? false,
            sales_unit: i?.sales_unit ?? 'bottles',
            description: i?.description ?? '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            default_price: toMinor(String(data.default_price)),
            cost_per_unit: toMinor(String(data.cost_per_unit)),
            min_stock: data.min_stock === '' ? null : data.min_stock,
            vintage: data.vintage === '' ? null : data.vintage,
            sales_unit: isPackaged.value ? data.sales_unit : null,
            // Not a form field — creating always packs by the six the design
            // assumes; editing leaves whatever the item already has alone
            // rather than silently overwriting it with that same guess.
            ...(isEdit.value ? {} : { bottles_per_case: isPackaged.value ? 6 : null }),
        }))
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/inventory/${props.item!.id}` : '/inventory', {
            preserveScroll: true,
            // A partial reload when editing — see CustomerPriceDialog.vue's
            // submit() for why a bare patch without `only` would drop every
            // other Optional prop the Inventory list may have loaded, namely
            // the Item — View drawer's itemMovements.
            only: isEdit.value ? ['items', 'itemMovements'] : undefined,
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <SidePanel :open="open" :title="isEdit ? t('Edit item') : t('New item')" @close="emit('close')">
        <form id="item-form" class="flex flex-col gap-4" @submit.prevent="submit">
            <FormField v-slot="{ id, invalid }" :label="t('Name')" required :error="form.errors.name">
                <Input :id="id" v-model="form.name" :invalid="invalid" :placeholder="t('e.g. Plavac Mali 2022')" />
            </FormField>

            <FormField
                v-slot="{ id, invalid }"
                :label="t('SKU')"
                required
                :hint="t('Generated from name and vintage. Edit if you use your own codes.')"
                :error="form.errors.sku"
            >
                <Input :id="id" v-model="form.sku" :invalid="invalid" placeholder="VT-PM-22" />
            </FormField>

            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Type')" required :error="form.errors.category">
                    <Select :id="id" v-model="form.category" :invalid="invalid" :options="TYPES" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Category')" :error="form.errors.group">
                    <Input :id="id" v-model="form.group" :invalid="invalid" :placeholder="t('Wine')" />
                </FormField>
            </FieldRow>

            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Unit size')" :error="form.errors.unit_size">
                    <Input :id="id" v-model="form.unit_size" :invalid="invalid" placeholder="750 ml" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Unit')" :error="form.errors.unit">
                    <Select :id="id" v-model="form.unit" :invalid="invalid" :options="UNITS" />
                </FormField>
            </FieldRow>

            <FormField
                v-slot="{ id, invalid }"
                :label="t('Default price')"
                :hint="t('Leave empty to calculate from recipe.')"
                :error="form.errors.default_price"
            >
                <Input :id="id" v-model="form.default_price" :invalid="invalid" placeholder="0,00 €" />
            </FormField>

            <Separator />

            <Disclosure :title="t('Advanced')" :summary="advancedSummary">
                <FormField v-slot="{ id, invalid }" :label="t('Subcategory')" :error="form.errors.subcategory">
                    <Input :id="id" v-model="form.subcategory" :invalid="invalid" :placeholder="t('e.g. Red Wine')" />
                </FormField>

                <FormField v-slot="{ id, invalid }" :label="t('Vintage')" :error="form.errors.vintage">
                    <Input :id="id" v-model="form.vintage" :invalid="invalid" :placeholder="t('e.g. 2024')" />
                </FormField>

                <FormField
                    v-slot="{ id, invalid }"
                    :label="t('Min stock')"
                    :hint="t('Alert when stock falls below this.')"
                    :error="form.errors.min_stock"
                >
                    <Input :id="id" v-model="form.min_stock" :invalid="invalid" placeholder="0" />
                </FormField>

                <FormField
                    v-slot="{ id, invalid }"
                    :label="t('Cost per unit')"
                    :hint="t('Leave empty to calculate from recipe.')"
                    :error="form.errors.cost_per_unit"
                >
                    <Input :id="id" v-model="form.cost_per_unit" :invalid="invalid" placeholder="0,00 €" />
                </FormField>

                <SwitchRow
                    v-model="form.is_for_sale"
                    :label="t('Available for sale')"
                    :hint="t('Shows in the customer order form. Needs a price set.')"
                />

                <FormField
                    v-if="isPackaged"
                    v-slot="{ id, invalid }"
                    :label="t('Sales unit')"
                    :hint="t('Unit shown when ordering.')"
                    :error="form.errors.sales_unit"
                >
                    <Select :id="id" v-model="form.sales_unit" :invalid="invalid" :options="SALES_UNITS" />
                </FormField>

                <FormField v-slot="{ id, invalid }" :label="t('Description')" :error="form.errors.description">
                    <Textarea
                        :id="id"
                        v-model="form.description"
                        :invalid="invalid"
                        :placeholder="t('Tasting notes, provenance, awards…')"
                    />
                </FormField>
            </Disclosure>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <button
                type="submit"
                form="item-form"
                class="inline-flex h-9 items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
                :disabled="form.processing"
            >
                {{ isEdit ? (form.processing ? t('Saving…') : t('Save changes')) : form.processing ? t('Creating…') : t('Create item') }}
            </button>
        </template>
    </SidePanel>
</template>
