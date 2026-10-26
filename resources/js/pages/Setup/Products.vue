<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { FileSpreadsheet, Plus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import BulkImportDialog from '@/components/inventory/BulkImportDialog.vue';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Select from '@/components/ui/Select.vue';
import { useAuth } from '@/composables/useAuth';
import { useTranslations } from '@/composables/useTranslations';
import type { SharedProps } from '@/types';

/** The shell persists between visits, so navigating does not remount the nav. */
defineOptions({ layout: AppLayout });

/**
 * The setup "Finished products" step (docs/21-onboarding-plan.md, phase 2):
 * two ways in, side by side — import the spreadsheet the winery already has
 * (the Inventory page's own BulkImportDialog), or type wines into this grid,
 * one row each, with only what an order needs. SKUs are generated and the
 * opening stock is booked as a stock movement
 * (App\Actions\Onboarding\CreateQuickProductsAction). Everything else about a
 * product is edited later on its own page.
 */
const props = defineProps<{
    categories: { id: string; label: string; has_vintage: boolean }[];
    productCount: number;
}>();

const page = usePage<SharedProps>();
const { can } = useAuth();
const { t } = useTranslations();

const currency = computed(() => page.props.org?.default_currency ?? 'EUR');

/** The welcome step sends people who have a file straight here with ?import=1. */
const importOpen = ref(new URLSearchParams(page.url.split('?', 2)[1] ?? '').get('import') === '1' && can('inventory.bulk'));

interface Row {
    name: string;
    product_category_id: string;
    vintage: string;
    unit_size: string;
    sales_unit: 'bottles' | 'cases';
    bottles_per_case: string;
    /** Major units, as typed. */
    price: string;
    opening_stock: string;
    is_for_sale: boolean;
}

/** New rows copy the previous row's category, size and packing — wines tend to be entered in runs. */
function blankRow(after?: Row): Row {
    return {
        name: '',
        product_category_id: after?.product_category_id ?? props.categories[0]?.id ?? '',
        vintage: '',
        unit_size: after?.unit_size ?? '0.75 L',
        sales_unit: after?.sales_unit ?? 'bottles',
        bottles_per_case: after?.bottles_per_case ?? '6',
        price: '',
        opening_stock: '',
        is_for_sale: true,
    };
}

const form = useForm<{ rows: Row[] }>({ rows: [blankRow(), blankRow(), blankRow()] });
const errors = computed(() => form.errors as Record<string, string>);

const categoryOptions = computed(() => [{ value: '', label: t('No category') }, ...props.categories.map((c) => ({ value: c.id, label: c.label }))]);
const salesUnitOptions = computed(() => [
    { value: 'bottles', label: t('Bottles') },
    { value: 'cases', label: t('Cases') },
]);

function addRow(): void {
    form.rows.push(blankRow(form.rows[form.rows.length - 1]));
}

function removeRow(index: number): void {
    form.rows.splice(index, 1);
    if (form.rows.length === 0) form.rows.push(blankRow());
}

const filled = computed(() => form.rows.filter((row) => row.name.trim() !== ''));

function toMinor(value: string): number | null {
    if (value.trim() === '') return null;
    const n = Number.parseFloat(value.replace(',', '.'));

    return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : null;
}

function toInt(value: string): number | null {
    if (value.trim() === '') return null;
    const n = Number.parseInt(value, 10);

    return Number.isFinite(n) ? n : null;
}

/** Only rows with a name are sent, so error keys index into `filled`; map them back to the visible row. */
const sentIndex = computed(() => new Map(filled.value.map((row, i) => [row, i])));

function cellError(row: Row, field: string): string | undefined {
    const i = sentIndex.value.get(row);

    return i === undefined ? undefined : errors.value[`rows.${i}.${field}`];
}

function submit(): void {
    form.transform((data) => ({
        rows: data.rows
            .filter((row) => row.name.trim() !== '')
            .map((row) => ({
                name: row.name.trim(),
                product_category_id: row.product_category_id === '' ? null : row.product_category_id,
                vintage: row.vintage.trim() === '' ? null : row.vintage.trim(),
                unit_size: row.unit_size.trim() === '' ? null : row.unit_size.trim(),
                sales_unit: row.sales_unit,
                bottles_per_case: toInt(row.bottles_per_case),
                default_price: toMinor(row.price),
                opening_stock: toInt(row.opening_stock),
                is_for_sale: row.is_for_sale,
            })),
    })).post('/setup/products', {
        preserveScroll: true,
        onSuccess: () => {
            form.rows = [blankRow(), blankRow(), blankRow()];
        },
    });
}
</script>

<template>
    <Head :title="t('Finished products')" />
    <div class="space-y-5">
        <PageHeader
            :title="t('Finished products')"
            :description="t('Add the wines you sell. Only the basics are needed now — descriptions, photos and recipes can follow on each product\'s page.')"
        >
            <template #actions>
                <Button variant="outline" size="sm" href="/inventory">{{ t('Open inventory (:count)', { count: productCount }) }}</Button>
            </template>
        </PageHeader>

        <Card v-if="can('inventory.bulk')" class="flex flex-wrap items-center justify-between gap-3 p-4">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <FileSpreadsheet class="mt-0.5 size-5 shrink-0 text-muted-foreground" :stroke-width="1.5" />
                <div>
                    <p class="text-sm font-medium text-foreground">{{ t('Have a spreadsheet?') }}</p>
                    <p class="text-xs text-muted-foreground">{{ t('Download the template, paste your products in, and import them all at once.') }}</p>
                </div>
            </div>
            <Button variant="outline" size="sm" @click="importOpen = true">{{ t('Import a CSV') }}</Button>
        </Card>

        <Card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] text-xs">
                    <thead class="border-b border-border bg-muted/40 text-left text-3xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Name') }} *</th>
                            <th scope="col" class="px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Category') }}</th>
                            <th scope="col" class="w-24 px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Vintage') }}</th>
                            <th scope="col" class="w-24 px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Size') }}</th>
                            <th scope="col" class="w-28 px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Sold by') }}</th>
                            <th scope="col" class="w-20 px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Per case') }}</th>
                            <th scope="col" class="w-28 px-3 py-2.5 font-medium tracking-[0.08em] uppercase">
                                {{ t('List price / bottle (:currency)', { currency }) }}
                            </th>
                            <th scope="col" class="w-24 px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('Bottles in stock') }}</th>
                            <th scope="col" class="w-16 px-3 py-2.5 font-medium tracking-[0.08em] uppercase">{{ t('For sale') }}</th>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">{{ t('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in form.rows" :key="i" class="border-b border-border last:border-b-0 align-top">
                            <td class="px-3 py-2">
                                <Input v-model="row.name" maxlength="255" :placeholder="t('e.g. Plavac Mali')" :invalid="Boolean(cellError(row, 'name'))" :aria-label="t('Name')" />
                                <p v-if="cellError(row, 'name')" class="mt-1 text-2xs text-destructive">{{ cellError(row, 'name') }}</p>
                            </td>
                            <td class="px-3 py-2">
                                <Select v-model="row.product_category_id" :options="categoryOptions" :invalid="Boolean(cellError(row, 'product_category_id'))" :aria-label="t('Category')" />
                            </td>
                            <td class="px-3 py-2">
                                <Input v-model="row.vintage" maxlength="20" inputmode="numeric" placeholder="2023" :invalid="Boolean(cellError(row, 'vintage'))" :aria-label="t('Vintage')" />
                            </td>
                            <td class="px-3 py-2">
                                <Input v-model="row.unit_size" maxlength="50" :invalid="Boolean(cellError(row, 'unit_size'))" :aria-label="t('Size')" />
                            </td>
                            <td class="px-3 py-2">
                                <Select v-model="row.sales_unit" :options="salesUnitOptions" :aria-label="t('Sold by')" />
                            </td>
                            <td class="px-3 py-2">
                                <Input v-model="row.bottles_per_case" type="number" min="1" step="1" :invalid="Boolean(cellError(row, 'bottles_per_case'))" :aria-label="t('Per case')" />
                            </td>
                            <td class="px-3 py-2">
                                <Input v-model="row.price" type="number" min="0" step="0.01" placeholder="0.00" :invalid="Boolean(cellError(row, 'default_price'))" :aria-label="t('List price')" />
                            </td>
                            <td class="px-3 py-2">
                                <Input v-model="row.opening_stock" type="number" min="0" step="1" placeholder="0" :invalid="Boolean(cellError(row, 'opening_stock'))" :aria-label="t('Bottles in stock')" />
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input v-model="row.is_for_sale" type="checkbox" class="mt-2.5 accent-primary" :aria-label="t('For sale')" />
                            </td>
                            <td class="px-3 py-2">
                                <button type="button" class="mt-1.5 p-1 text-muted-foreground hover:text-destructive" :title="t('Remove row')" @click="removeRow(i)">
                                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-3">
                <Button variant="ghost" size="sm" @click="addRow">
                    <Plus class="size-3.5" :stroke-width="1.5" />
                    {{ t('Add row') }}
                </Button>
                <div class="flex items-center gap-3">
                    <p v-if="errors['rows']" class="text-xs text-destructive">{{ errors['rows'] }}</p>
                    <Button size="sm" :disabled="form.processing || filled.length === 0" @click="submit">
                        {{ form.processing ? t('Saving…') : t('Save :count products', { count: filled.length }) }}
                    </Button>
                </div>
            </div>
        </Card>

        <BulkImportDialog :open="importOpen" @close="importOpen = false" />
    </div>
</template>
