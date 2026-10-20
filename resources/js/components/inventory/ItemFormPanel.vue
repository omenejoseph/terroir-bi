<script setup lang="ts">
import { computed, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Separator from '@/components/ui/Separator.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { isWholeUnit } from '@/lib/stock';
import { useTranslations } from '@/composables/useTranslations';
import type { ProductCategory, InventoryItem, VintageOption } from '@/types/inventory';
import type { ComboboxOption } from '@/types/ui';

/**
 * New / Edit item (Figma `317:468`, and `322:704` with Advanced open — the
 * design only draws creation; editing reuses the same fields against
 * UpdateInventoryItemRequest, the same way TaskPanel and CustomerFormPanel are
 * one component for both).
 *
 * Naming note: the design's "Type" is our `category` (the InventoryCategory
 * enum) and the design's "Category" is a ProductCategory record, managed on
 * Inventory · Categories — one that carries vintages also unlocks the vintage
 * and previous-vintage fields here.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        item: InventoryItem | null;
        /** Which page props the post-edit partial reload asks for — see submit(). */
        reloadOnly?: string[];
    }>(),
    { reloadOnly: () => ['items', 'itemMovements'] },
);
const emit = defineEmits<{ close: [] }>();
const { t } = useTranslations();

const isEdit = computed(() => props.item !== null);

const page = usePage();
const categoryTree = computed<ProductCategory[]>(() => (page.props.productCategories as ProductCategory[] | undefined) ?? []);
const vintageOptions = computed<VintageOption[]>(() => (page.props.vintageOptions as VintageOption[] | undefined) ?? []);

const form = useForm({
    name: '',
    sku: '',
    category: 'FINISHED',
    product_category_id: '' as string,
    unit_size: '',
    unit: 'bottles',
    default_price: '',
    vintage: '',
    previous_vintage_id: '' as string,
    min_stock: '',
    cost_per_unit: '',
    is_for_sale: false,
    sales_unit: 'bottles',
    bottles_per_case: '6',
    pack_size: '1',
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
            product_category_id: i?.product_category_id ?? '',
            unit_size: i?.unit_size ?? '',
            unit: i?.unit ?? 'bottles',
            default_price: i?.default_price ? String(i.default_price.minor / 100) : '',
            vintage: i?.vintage === null || i?.vintage === undefined ? '' : String(i.vintage),
            previous_vintage_id: i?.previous_vintage_id ?? '',
            min_stock: i?.min_stock ?? '',
            cost_per_unit: i?.cost_per_unit ? String(i.cost_per_unit.minor / 100) : '',
            is_for_sale: i?.is_for_sale ?? false,
            sales_unit: i?.sales_unit ?? 'bottles',
            bottles_per_case: i?.bottles_per_case ? String(i.bottles_per_case) : '6',
            pack_size: i?.pack_size ? String(i.pack_size) : '1',
            description: i?.description ?? '',
        });
        form.reset();
        form.clearErrors();

        // The category and previous-vintage pickers need their lists; the pages load them lazily, so
        // ask for them the first time a form actually opens.
        if (categoryTree.value.length === 0 || vintageOptions.value.length === 0) {
            router.reload({ only: ['productCategories', 'vintageOptions'] });
        }
    },
    { immediate: true },
);

/** Categories of the chosen type — top-level ones, then each with its subcategories as "Wine · Red". */
const categoryOptions = computed<ComboboxOption[]>(() =>
    categoryTree.value
        .filter((c) => c.type === form.category)
        .flatMap((c) => [
            { value: c.id, label: c.name },
            ...(c.children ?? []).map((sub) => ({ value: sub.id, label: `${c.name} · ${sub.name}` })),
        ]),
);

const selectedCategory = computed<ProductCategory | null>(() => {
    for (const c of categoryTree.value) {
        if (c.id === form.product_category_id) return c;

        const sub = c.children?.find((s) => s.id === form.product_category_id);
        if (sub) return sub;
    }

    return null;
});

/** A category that carries vintages unlocks the vintage fields; an item that already has one keeps showing it. */
const showVintage = computed(() => selectedCategory.value?.has_vintage === true || form.vintage !== '');

// A category belongs to a type, so changing the type drops a category that no longer fits.
watch(
    () => form.category,
    () => {
        if (form.product_category_id !== '' && !categoryOptions.value.some((o) => o.value === form.product_category_id)) {
            form.product_category_id = '';
            form.previous_vintage_id = '';
        }
    },
);

/** Products that can be the previous vintage: same type, not this one, not already followed by another. */
const previousOptions = computed<ComboboxOption[]>(() =>
    vintageOptions.value
        .filter((o) => o.type === form.category && o.id !== props.item?.id && (!o.has_next || o.id === props.item?.previous_vintage_id))
        .map((o) => ({
            value: o.id,
            label: [o.name, o.vintage].filter(Boolean).join(' ') + (o.unit_size ? ` — ${o.unit_size}` : ''),
            keywords: [o.vintage ?? '', o.unit_size ?? ''],
        })),
);

/** Picking the previous vintage fills in what a new vintage shares with it — the name and bottle size. */
function pickPrevious(id: string | null): void {
    form.previous_vintage_id = id ?? '';

    const picked = vintageOptions.value.find((o) => o.id === id);
    if (!picked || isEdit.value) return;

    if (form.name.trim() === '') form.name = picked.name;
    if (form.unit_size.trim() === '') form.unit_size = picked.unit_size ?? '';
}

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            default_price: toMinor(String(data.default_price)),
            cost_per_unit: toMinor(String(data.cost_per_unit)),
            min_stock: data.min_stock === '' ? null : data.min_stock,
            product_category_id: data.product_category_id === '' ? null : data.product_category_id,
            vintage: data.vintage === '' ? null : data.vintage,
            // Only a category that carries vintages has a chain to follow. Until the categories have
            // loaded we can't tell, so the link is left alone rather than cleared.
            previous_vintage_id:
                data.previous_vintage_id === '' || (!showVintage.value && categoryTree.value.length > 0)
                    ? null
                    : showVintage.value
                      ? data.previous_vintage_id
                      : undefined,
            sales_unit: isPackaged.value ? data.sales_unit : null,
            // Raw materials bought by the pack: cost per unit is the price of a pack.
            pack_size: data.category === 'RAW_MATERIAL' && /^[1-9]\d*$/.test(data.pack_size) ? Number(data.pack_size) : undefined,
            // How many make one case, so an item can be stocked in bottles and still sold by the case.
            bottles_per_case: /^[1-9]\d*$/.test(data.bottles_per_case) ? Number(data.bottles_per_case) : isEdit.value ? undefined : null,
        }))
        .submit(isEdit.value ? 'patch' : 'post', isEdit.value ? `/inventory/${props.item!.id}` : '/inventory', {
            preserveScroll: true,
            // A partial reload when editing — see CustomerPriceDialog.vue's
            // submit() for why a bare patch without `only` would drop every
            // other Optional prop the current page may have loaded. Which keys
            // to ask for differs by consumer (the Inventory list's own
            // itemMovements vs. Product Detail's single `item`), hence the prop
            // rather than a value hard-coded here.
            only: isEdit.value ? props.reloadOnly : undefined,
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
                <FormField
                    v-slot="{ id, invalid }"
                    :label="t('Category')"
                    :hint="categoryOptions.length === 0 ? t('No categories for this type yet — add them on the Categories tab.') : undefined"
                    :error="form.errors.product_category_id"
                >
                    <Combobox
                        :id="id"
                        :model-value="form.product_category_id === '' ? null : form.product_category_id"
                        :invalid="invalid"
                        :options="categoryOptions"
                        :placeholder="t('No category')"
                        :empty-text="t('No category matches.')"
                        clearable
                        @update:model-value="form.product_category_id = $event ?? ''"
                    />
                </FormField>
            </FieldRow>

            <template v-if="showVintage">
                <FormField v-slot="{ id, invalid }" :label="t('Vintage')" :error="form.errors.vintage">
                    <Input :id="id" v-model="form.vintage" :invalid="invalid" :placeholder="t('e.g. 2024')" />
                </FormField>

                <FormField
                    v-slot="{ id, invalid }"
                    :label="t('Previous vintage')"
                    :hint="t('The vintage this one follows. Older vintages then sit under the latest one on the list, and the photo carries over.')"
                    :error="form.errors.previous_vintage_id"
                >
                    <Combobox
                        :id="id"
                        :model-value="form.previous_vintage_id === '' ? null : form.previous_vintage_id"
                        :invalid="invalid"
                        :options="previousOptions"
                        :placeholder="t('None — this is the first vintage')"
                        :empty-text="t('No product matches.')"
                        clearable
                        @update:model-value="pickPrevious"
                    />
                </FormField>
            </template>

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
                <FormField
                    v-slot="{ id, invalid }"
                    :label="t('Min stock')"
                    :hint="t('Alert when stock falls below this.')"
                    :error="form.errors.min_stock"
                >
                    <Input
                        :id="id"
                        v-model="form.min_stock"
                        :invalid="invalid"
                        placeholder="0"
                        :inputmode="isWholeUnit(form.unit) ? 'numeric' : 'decimal'"
                        @input="isWholeUnit(form.unit) && (form.min_stock = form.min_stock.replace(/[^0-9]/g, ''))"
                    />
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
                    v-if="form.category === 'RAW_MATERIAL'"
                    v-slot="{ id, invalid }"
                    :label="t('Pack size')"
                    :hint="t('Units per pack, e.g. 12 for a box of 12. Cost per unit is then the price of a pack, and recipes use cost ÷ pack size.')"
                    :error="form.errors.pack_size"
                >
                    <Input
                        :id="id"
                        v-model="form.pack_size"
                        :invalid="invalid"
                        inputmode="numeric"
                        placeholder="1"
                        @input="form.pack_size = form.pack_size.replace(/[^0-9]/g, '')"
                    />
                </FormField>

                <FormField
                    v-slot="{ id, invalid }"
                    :label="isPackaged ? t('Bottles per case') : t('Units per case')"
                    :hint="t('How many make one case — lets you stock in bottles and still sell by the case.')"
                    :error="form.errors.bottles_per_case"
                >
                    <Input
                        :id="id"
                        v-model="form.bottles_per_case"
                        :invalid="invalid"
                        inputmode="numeric"
                        placeholder="6"
                        @input="form.bottles_per_case = form.bottles_per_case.replace(/[^0-9]/g, '')"
                    />
                </FormField>

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
