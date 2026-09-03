<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';

import Badge from '@/components/ui/Badge.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import { useTranslations } from '@/composables/useTranslations';
import { cn } from '@/lib/cn';
import { formatQuantity } from '@/lib/money';
import { categoryLabel } from '@/lib/stock';
import type { InventoryItem } from '@/types/inventory';

/**
 * Bulk edit (Figma `270:9646`) — the inventory list turned editable in place.
 *
 * Edited rows are tinted and counted in the footer, and only the fields that
 * actually changed are sent, so an untouched row is never written.
 *
 * The design shows Stock as editable; it is read-only here. Stock is derived
 * from the movement ledger, so writing it directly would leave the running
 * balance and movement history disagreeing with the item. Inventory Check is
 * the path for reconciling a physical count, and it records real movements.
 */
const props = defineProps<{ items: InventoryItem[]; locale: string }>();
const emit = defineEmits<{ cancel: [] }>();
const { t } = useTranslations();

interface Draft {
    name: string;
    min_stock: string;
    default_price: string;
    cost_per_unit: string;
    is_active: boolean;
    is_for_sale: boolean;
}

/**
 * Decimal columns arrive as "5000.000". Editing that is unpleasant and saving
 * it back unchanged would register as a change, so it is trimmed on seed and
 * the original is trimmed identically for the dirty check.
 */
const trimDecimal = (value: string | null): string =>
    value === null || value === '' ? '' : (rtrim(value) ?? '');

function rtrim(value: string): string {
    return value.includes('.') ? value.replace(/\.?0+$/, '') : value;
}

/** Major units for display; converted back to minor on save. */
const toMajor = (minor: number | undefined | null) => (minor === undefined || minor === null ? '' : String(minor / 100));
const toMinor = (major: string) => {
    if (major.trim() === '') return null;
    const n = Number.parseFloat(major.replace(',', '.'));

    return Number.isFinite(n) ? Math.round(n * 100) : null;
};

function draftFor(item: InventoryItem): Draft {
    return {
        name: item.name,
        min_stock: trimDecimal(item.min_stock),
        default_price: toMajor(item.default_price?.minor),
        cost_per_unit: toMajor(item.cost_per_unit?.minor),
        is_active: item.is_active,
        is_for_sale: item.is_for_sale,
    };
}

const drafts = reactive<Record<string, Draft>>({});

function seed(): void {
    for (const key of Object.keys(drafts)) delete drafts[key];
    for (const item of props.items) drafts[item.id] = draftFor(item);
}

seed();
watch(() => props.items, seed, { deep: false });

function isDirty(item: InventoryItem): boolean {
    const draft = drafts[item.id];

    if (!draft) return false;

    const original = draftFor(item);

    return (Object.keys(original) as (keyof Draft)[]).some((k) => draft[k] !== original[k]);
}

const dirtyItems = computed(() => props.items.filter(isDirty));

/**
 * One row of the bulk payload. Mirrors BulkUpdateInventoryItemsRequest's
 * `items.*` rules, which is why every field but `id` is optional.
 */
interface BulkRow {
    id: string;
    name?: string;
    min_stock?: string | null;
    default_price?: number | null;
    cost_per_unit?: number | null;
    is_active?: boolean;
    is_for_sale?: boolean;
    /** Inertia's payload type requires an index signature on nested objects. */
    [key: string]: string | number | boolean | null | undefined;
}

/** Only changed fields travel, so a partial edit never overwrites a sibling column. */
function payload(): BulkRow[] {
    return dirtyItems.value.map((item) => {
        const draft = drafts[item.id]!;
        const original = draftFor(item);
        const row: BulkRow = { id: item.id };

        if (draft.name !== original.name) row.name = draft.name;
        if (draft.min_stock !== original.min_stock) row.min_stock = draft.min_stock === '' ? null : draft.min_stock;
        if (draft.default_price !== original.default_price) row.default_price = toMinor(draft.default_price);
        if (draft.cost_per_unit !== original.cost_per_unit) row.cost_per_unit = toMinor(draft.cost_per_unit);
        if (draft.is_active !== original.is_active) row.is_active = draft.is_active;
        if (draft.is_for_sale !== original.is_for_sale) row.is_for_sale = draft.is_for_sale;

        return row;
    });
}

function save(): void {
    if (dirtyItems.value.length === 0) return;

    router.patch('/inventory-bulk', { items: payload() }, { preserveScroll: true, onSuccess: () => emit('cancel') });
}

defineExpose({ dirtyCount: computed(() => dirtyItems.value.length), save });

const cellInput =
    'h-8 w-full rounded-md border border-transparent bg-transparent px-2 text-sm transition-colors hover:border-input focus:border-input focus:bg-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <div class="flex flex-col">
        <div class="overflow-x-auto rounded-lg border border-border bg-card">
            <table class="w-full min-w-[64rem] text-sm">
                <thead class="border-b border-border text-left text-3xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="w-[22rem] px-4 py-2.5 font-medium">{{ t('Name') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">{{ t('SKU') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Category') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Stock') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Min Stock') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Price') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">{{ t('Cost/Unit') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Active') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr
                        v-for="item in items"
                        :key="item.id"
                        :class="cn(isDirty(item) && 'bg-[color-mix(in_oklch,var(--color-primary)_5%,transparent)]')"
                    >
                        <td class="px-2 py-1.5">
                            <input v-model="drafts[item.id]!.name" :class="cellInput" :aria-label="t('Name')" />
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">{{ item.sku }}</td>
                        <td class="px-4 py-3"><Badge variant="outline">{{ categoryLabel(item.category) }}</Badge></td>
                        <!-- Read-only: stock is owned by the movement ledger. -->
                        <td
                            class="px-4 py-3 text-right tabular-nums text-muted-foreground"
                            :title="t('Stock is derived from stock movements — use Inventory Check to reconcile a physical count')"
                        >
                            {{ formatQuantity(item.current_stock, locale) }}
                        </td>
                        <td class="px-2 py-1.5">
                            <input
                                v-model="drafts[item.id]!.min_stock"
                                :class="cn(cellInput, 'text-right tabular-nums')"
                                inputmode="decimal"
                                :aria-label="t('Minimum stock')"
                            />
                        </td>
                        <td class="px-2 py-1.5">
                            <input
                                v-model="drafts[item.id]!.default_price"
                                :class="cn(cellInput, 'text-right tabular-nums')"
                                inputmode="decimal"
                                :aria-label="t('Price')"
                            />
                        </td>
                        <td class="px-2 py-1.5">
                            <input
                                v-model="drafts[item.id]!.cost_per_unit"
                                :class="cn(cellInput, 'text-right tabular-nums')"
                                inputmode="decimal"
                                :aria-label="t('Cost per unit')"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <Checkbox v-model="drafts[item.id]!.is_active" label="" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-if="dirtyItems.length"
            class="border border-t-0 border-border bg-[color-mix(in_oklch,var(--color-primary)_5%,transparent)] px-4 py-2.5 text-xs font-medium"
        >
            {{ t(':count item(s) modified', { count: dirtyItems.length }) }}
        </p>
    </div>
</template>
