<script setup lang="ts">
import { computed, ref } from 'vue';
import { FileText, Trash2 } from 'lucide-vue-next';

import QuantityStepper from '@/components/orders/QuantityStepper.vue';
import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import { useTranslations } from '@/composables/useTranslations';
import { formatMoney } from '@/lib/money';
import { UNIT_OPTIONS } from '@/lib/orders';
import type { OrderLineDraft, ProductOption } from '@/types/orders';
import type { ComboboxOption } from '@/types/ui';

/**
 * One or more editable order lines: pick a catalog product (its unit is
 * locked and the server prices it) or add a custom line (free unit,
 * description and price). This is the one place that split — the one
 * StoreOrderRequest and AddOrderItemsRequest both enforce — is drawn, shared
 * by CreateOrderPanel (a whole new order) and OrderViewPanel's "Add item"
 * panel (lines appended to an existing one), mirroring how the outgoing React
 * app extracted OrderLineItemsEditor for the identical reason.
 *
 * A plain `v-model` on each field isn't used here — `modelValue` is a prop,
 * and mutating a prop's nested objects directly is exactly the pattern Vue's
 * one-way data flow warns against — so every change goes through `patch()`
 * and is emitted back up.
 */
const props = defineProps<{ modelValue: OrderLineDraft[]; products: ProductOption[] }>();
const emit = defineEmits<{ 'update:modelValue': [OrderLineDraft[]] }>();

const { t } = useTranslations();

const picked = ref('');
let seq = 0;
const nextKey = (): string => `line-${(seq += 1)}`;

function addProduct(id: string): void {
    const product = props.products.find((p) => p.id === id);
    if (!product) return;

    emit('update:modelValue', [
        ...props.modelValue,
        {
            key: nextKey(),
            inventory_item_id: product.id,
            custom_description: null,
            quantity: 1,
            // A catalog item can only be ordered in its own sales unit; the
            // server rejects anything else, so the picker is fixed rather
            // than free.
            unit_type: product.sales_unit,
            unit_price: null,
            preview: product.list_price,
            label: [product.name, product.vintage].filter(Boolean).join(' '),
            meta: [product.unit_size, product.sku].filter(Boolean).join(' · '),
        },
    ]);
    picked.value = '';
}

function addCustom(): void {
    emit('update:modelValue', [
        ...props.modelValue,
        {
            key: nextKey(),
            inventory_item_id: null,
            custom_description: '',
            quantity: 1,
            unit_type: 'bottles',
            unit_price: 0,
            preview: null,
            label: '',
            meta: null,
        },
    ]);
}

function remove(key: string): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((line) => line.key !== key),
    );
}

function patch(key: string, changes: Partial<OrderLineDraft>): void {
    emit(
        'update:modelValue',
        props.modelValue.map((line) => (line.key === key ? { ...line, ...changes } : line)),
    );
}

const currency = computed(() => props.modelValue.find((line) => line.preview)?.preview?.currency ?? 'EUR');

/*
  The design's placeholder — "Search product by name, SKU or vintage…" — is a
  promise about what matches. SKU and vintage go in `keywords` so they are
  searchable without cluttering every label with them.
*/
const PRODUCT_OPTIONS = computed<ComboboxOption[]>(() =>
    props.products
        .filter((p) => !props.modelValue.some((line) => line.inventory_item_id === p.id))
        .map((p) => ({
            value: p.id,
            label: [p.name, p.vintage].filter(Boolean).join(' '),
            description: p.unit_size ?? undefined,
            keywords: [p.sku, p.vintage === null ? '' : String(p.vintage)].filter(Boolean) as string[],
        })),
);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-end gap-2">
            <Button variant="outline" size="sm" type="button" @click="addCustom">
                <FileText class="size-3.5" :stroke-width="1.5" />
                {{ t('Custom item') }}
            </Button>
            <slot name="actions" />
        </div>

        <div v-for="line in modelValue" :key="line.key" class="border border-border p-3">
            <div class="flex items-start gap-3">
                <span class="size-9 shrink-0 border border-border bg-muted" aria-hidden="true" />

                <span v-if="line.inventory_item_id" class="min-w-0 flex-1">
                    <span class="block truncate text-sm">{{ line.label }}</span>
                    <span class="block truncate text-xs text-muted-foreground">
                        {{ line.preview ? formatMoney(line.preview.minor, line.preview.currency) : '—' }}
                        / {{ line.unit_type === 'cases' ? t('case') : t('bottle') }}
                        <template v-if="line.meta"> · {{ line.meta }}</template>
                    </span>
                </span>

                <span v-else class="min-w-0 flex-1">
                    <Input
                        :model-value="line.custom_description ?? ''"
                        :placeholder="t('e.g. Delivery, Packaging, Customs…')"
                        :aria-label="t('Custom line description')"
                        @update:model-value="patch(line.key, { custom_description: $event })"
                    />
                </span>

                <span class="shrink-0 text-sm font-semibold tabular-nums">
                    {{
                        formatMoney(
                            (line.inventory_item_id ? (line.preview?.minor ?? 0) : (line.unit_price ?? 0)) * line.quantity,
                            currency,
                        )
                    }}
                </span>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <QuantityStepper :model-value="line.quantity" @update:model-value="patch(line.key, { quantity: $event })" />

                <!-- A catalog line's unit is fixed by the item; only a custom line may choose. -->
                <span
                    v-if="line.inventory_item_id"
                    class="inline-flex h-7 items-center border border-border px-2.5 text-xs text-muted-foreground"
                >
                    {{ line.unit_type === 'cases' ? t('Cases') : t('Bottles') }}
                </span>
                <Select
                    v-else
                    :model-value="line.unit_type"
                    :options="UNIT_OPTIONS"
                    class="h-7 w-24 text-xs"
                    :aria-label="t('Unit')"
                    @update:model-value="patch(line.key, { unit_type: $event })"
                />

                <!--
                  A custom line must carry its own price — the catalog cannot
                  supply one — so the request validation requires it and the
                  row asks for it inline.
                -->
                <input
                    v-if="!line.inventory_item_id"
                    :value="(line.unit_price ?? 0) / 100"
                    type="number"
                    step="0.01"
                    min="0"
                    :placeholder="t('0,00')"
                    :aria-label="t('Unit price')"
                    class="h-7 w-20 border border-border bg-card px-2 text-xs tabular-nums focus-visible:outline-none"
                    @input="
                        patch(line.key, {
                            unit_price: Math.round(Number.parseFloat(($event.target as HTMLInputElement).value || '0') * 100),
                        })
                    "
                />

                <button
                    type="button"
                    class="ml-auto p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                    :aria-label="t('Remove :item', { item: line.label || t('line') })"
                    @click="remove(line.key)"
                >
                    <Trash2 class="size-4" :stroke-width="1.5" />
                </button>
            </div>
        </div>

        <Combobox
            :model-value="picked === '' ? null : picked"
            :placeholder="t('Search product by name, SKU or vintage…')"
            :empty-text="t('No product matches.')"
            :options="PRODUCT_OPTIONS"
            @update:model-value="$event && addProduct($event)"
        />
    </div>
</template>
