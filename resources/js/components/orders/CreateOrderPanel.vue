<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Camera, FileText, Plus, Trash2 } from 'lucide-vue-next';

import QuantityStepper from '@/components/orders/QuantityStepper.vue';
import Button from '@/components/ui/Button.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { formatMoney } from '@/lib/money';
import type { MoneyValue } from '@/types/inventory';
import type { SharedProps } from '@/types';

/**
 * Create order (Figma `335:4233`, with `335:4331` for the open disclosure).
 *
 * Two kinds of line exist and the server distinguishes them: a catalog line
 * carries `inventory_item_id` and lets the server price it, while a custom line
 * carries a description and an explicit price. StoreOrderRequest enforces that
 * split, so this form is built around it rather than around one generic row.
 *
 * Prices shown here are the catalog list price. The customer's tier and rebate
 * are applied by OrderLineWriter when the order is written, so the subtotal is
 * an estimate and says so — quoting a number the server will not honour would
 * be worse than admitting the estimate.
 */
const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

interface CustomerOption {
    id: string;
    company_name: string;
    customer_type: string | null;
    city: string | null;
    rebate_percent: string;
}

interface ProductOption {
    id: string;
    name: string;
    sku: string;
    vintage: number | null;
    unit_size: string | null;
    sales_unit: string;
    bottles_per_case: number | null;
    list_price: MoneyValue | null;
}

/** One entry of StoreOrderRequest's `items` array. */
interface LinePayload {
    inventory_item_id: string | null;
    quantity: number;
    unit_type: string;
    unit_price?: number;
    custom_description?: string | null;
}

interface Line {
    key: string;
    inventory_item_id: string | null;
    custom_description: string | null;
    quantity: number;
    unit_type: string;
    /** Minor units. Only sent for custom lines; the server prices catalog ones. */
    unit_price: number | null;
    /** Catalog list price, for the estimate only. */
    preview: MoneyValue | null;
    label: string;
    meta: string | null;
}

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);

const customers = computed<CustomerOption[]>(
    () => (page.props.customerOptions as CustomerOption[] | undefined) ?? [],
);
const products = computed<ProductOption[]>(
    () => (page.props.productOptions as ProductOption[] | undefined) ?? [],
);

/*
  Both pickers are whole-catalog scans, so they are only fetched when the drawer
  actually opens — and only once per visit.
*/
watch(
    () => props.open,
    (open) => {
        if (open && customers.value.length === 0) {
            router.reload({ only: ['customerOptions', 'productOptions'] });
        }
    },
);

const lines = ref<Line[]>([]);
const picked = ref('');

const form = useForm({
    customer_id: '',
    notes: '',
    status: 'RECEIVED',
    is_backorder: false,
    is_consignment: false,
    shipping_cost: '',
    shipping_paid_by_us: true,
    items: [] as LinePayload[],
});

let seq = 0;
const nextKey = (): string => `line-${(seq += 1)}`;

function addProduct(id: string): void {
    const product = products.value.find((p) => p.id === id);
    if (!product) return;

    lines.value.push({
        key: nextKey(),
        inventory_item_id: product.id,
        custom_description: null,
        quantity: 1,
        // A catalog item can only be ordered in its own sales unit; the server
        // rejects anything else, so the picker is fixed rather than free.
        unit_type: product.sales_unit,
        unit_price: null,
        preview: product.list_price,
        label: [product.name, product.vintage].filter(Boolean).join(' '),
        meta: [product.unit_size, product.sku].filter(Boolean).join(' · '),
    });

    picked.value = '';
}

function addCustom(): void {
    lines.value.push({
        key: nextKey(),
        inventory_item_id: null,
        custom_description: '',
        quantity: 1,
        unit_type: 'bottles',
        unit_price: 0,
        preview: null,
        label: '',
        meta: null,
    });
}

function remove(key: string): void {
    lines.value = lines.value.filter((line) => line.key !== key);
}

const currency = computed(
    () => lines.value.find((line) => line.preview)?.preview?.currency ?? 'EUR',
);

/** Estimate only — see the note at the top of this component. */
const subtotal = computed(() =>
    lines.value.reduce((sum, line) => {
        const unit = line.inventory_item_id ? (line.preview?.minor ?? 0) : (line.unit_price ?? 0);

        return sum + unit * line.quantity;
    }, 0),
);

const canSubmit = computed(
    () =>
        form.customer_id !== '' &&
        lines.value.length > 0 &&
        lines.value.every(
            (line) => line.inventory_item_id !== null || (line.custom_description ?? '').trim() !== '',
        ),
);

const PRODUCT_OPTIONS = computed(() =>
    products.value
        .filter((p) => !lines.value.some((line) => line.inventory_item_id === p.id))
        .map((p) => ({
            value: p.id,
            label: [p.name, p.vintage, p.unit_size].filter(Boolean).join(' · '),
        })),
);

const CUSTOMER_OPTIONS = computed(() =>
    customers.value.map((c) => ({
        value: c.id,
        label: [c.company_name, c.city].filter(Boolean).join(' · '),
    })),
);

const STATUS_OPTIONS = [
    { value: 'RECEIVED', label: 'Received' },
    { value: 'IN_PROCESS', label: 'In Process' },
    { value: 'READY_TO_SHIP', label: 'Ready to Ship' },
    { value: 'SHIPPED', label: 'Shipped' },
];

const UNIT_OPTIONS = [
    { value: 'bottles', label: 'Bottles' },
    { value: 'cases', label: 'Cases' },
];

/** The collapsed disclosure summarises what it hides (Figma 335:4233). */
const settingsSummary = computed(() =>
    [
        STATUS_OPTIONS.find((s) => s.value === form.status)?.label ?? form.status,
        form.is_backorder ? 'Backorder' : 'Not backorder',
        form.is_consignment ? 'Consignment' : null,
        form.shipping_paid_by_us ? 'We pay logistics' : 'Customer pays logistics',
    ]
        .filter(Boolean)
        .join(' · '),
);

function submit(): void {
    form.items = lines.value.map((line) => ({
        inventory_item_id: line.inventory_item_id,
        quantity: line.quantity,
        unit_type: line.unit_type,
        // Only custom lines carry a price; sending one for a catalog line would
        // override the customer's negotiated price with the list price.
        ...(line.inventory_item_id === null
            ? { unit_price: line.unit_price ?? 0, custom_description: line.custom_description }
            : {}),
    }));

    form
        .transform((data) => ({
            ...data,
            shipping_cost: data.shipping_cost === '' ? null : Math.round(Number(data.shipping_cost) * 100),
        }))
        .post('/orders', {
            onSuccess: () => {
                form.reset();
                lines.value = [];
                emit('close');
            },
        });
}
</script>

<template>
    <SidePanel :open="open" title="Create order" @close="emit('close')">
        <form id="create-order" class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField label="Customer" required :error="form.errors.customer_id">
                <template #default="{ id, invalid }">
                    <div class="flex items-center gap-2">
                        <Select
                            :id="id"
                            v-model="form.customer_id"
                            :invalid="invalid"
                            placeholder="Select a customer…"
                            :options="CUSTOMER_OPTIONS"
                            class="flex-1"
                        />
                        <!-- @todo New customer inline. The design opens the
                             Customer — Create drawer from here; that drawer
                             arrives with Phase 4. -->
                        <button
                            type="button"
                            class="inline-flex size-9 shrink-0 items-center justify-center border border-border text-muted-foreground hover:border-foreground/40 hover:text-foreground"
                            aria-label="New customer"
                        >
                            <Plus class="size-4" :stroke-width="1.5" />
                        </button>
                    </div>
                </template>
            </FormField>

            <section class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm leading-5 font-medium text-foreground">
                        Products<span class="ml-1 text-destructive" aria-hidden="true">*</span>
                    </span>
                    <div class="flex items-center gap-2">
                        <Button variant="outline" size="sm" @click="addCustom">
                            <FileText class="size-3.5" :stroke-width="1.5" />
                            Custom item
                        </Button>
                        <!-- @todo Import screenshot. The design offers reading an
                             order off a photo; there is no such endpoint. -->
                        <Button variant="outline" size="sm">
                            <Camera class="size-3.5" :stroke-width="1.5" />
                            Import screenshot
                        </Button>
                    </div>
                </div>

                <div v-for="line in lines" :key="line.key" class="border border-border p-3">
                    <div class="flex items-start gap-3">
                        <span class="size-9 shrink-0 border border-border bg-muted" aria-hidden="true" />

                        <span v-if="line.inventory_item_id" class="min-w-0 flex-1">
                            <span class="block truncate text-sm">{{ line.label }}</span>
                            <span class="block truncate text-xs text-muted-foreground">
                                {{ line.preview ? formatMoney(line.preview.minor, line.preview.currency, locale) : '—' }}
                                / {{ line.unit_type === 'cases' ? 'case' : 'bottle' }}
                                <template v-if="line.meta"> · {{ line.meta }}</template>
                            </span>
                        </span>

                        <span v-else class="min-w-0 flex-1">
                            <Input
                                v-model="line.custom_description!"
                                placeholder="e.g. Delivery, Packaging, Customs…"
                                aria-label="Custom line description"
                            />
                        </span>

                        <span class="shrink-0 text-sm font-semibold tabular-nums">
                            {{
                                formatMoney(
                                    (line.inventory_item_id ? (line.preview?.minor ?? 0) : (line.unit_price ?? 0)) *
                                        line.quantity,
                                    currency,
                                    locale,
                                )
                            }}
                        </span>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <QuantityStepper v-model="line.quantity" />

                        <!-- A catalog line's unit is fixed by the item; only a
                             custom line may choose. -->
                        <span
                            v-if="line.inventory_item_id"
                            class="inline-flex h-7 items-center border border-border px-2.5 text-xs text-muted-foreground"
                        >
                            {{ line.unit_type === 'cases' ? 'Cases' : 'Bottles' }}
                        </span>
                        <Select
                            v-else
                            v-model="line.unit_type"
                            :options="UNIT_OPTIONS"
                            class="h-7 w-24 text-xs"
                            aria-label="Unit"
                        />

                        <!--
                          A custom line must carry its own price — the catalog
                          cannot supply one — so StoreOrderRequest requires it
                          and the row asks for it inline.
                        -->
                        <input
                            v-if="!line.inventory_item_id"
                            :value="(line.unit_price ?? 0) / 100"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0,00"
                            aria-label="Unit price"
                            class="h-7 w-20 border border-border bg-card px-2 text-xs tabular-nums focus-visible:outline-none"
                            @input="
                                line.unit_price = Math.round(
                                    Number.parseFloat(($event.target as HTMLInputElement).value || '0') * 100,
                                )
                            "
                        />

                        <button
                            type="button"
                            class="ml-auto p-1.5 text-muted-foreground transition-colors hover:text-destructive"
                            :aria-label="`Remove ${line.label || 'line'}`"
                            @click="remove(line.key)"
                        >
                            <Trash2 class="size-4" :stroke-width="1.5" />
                        </button>
                    </div>
                </div>

                <Select
                    v-model="picked"
                    placeholder="Search product by name, SKU or vintage…"
                    :options="PRODUCT_OPTIONS"
                    aria-label="Add a product"
                    @update:model-value="addProduct"
                />

                <p v-if="form.errors.items" class="text-xs text-destructive" role="alert">{{ form.errors.items }}</p>
            </section>

            <div class="flex items-baseline justify-between gap-3 border-t border-border pt-4">
                <span class="text-sm">Subtotal · excl. VAT</span>
                <span class="text-lg font-semibold tabular-nums">
                    {{ formatMoney(subtotal, currency, locale) }}
                </span>
            </div>
            <p class="-mt-3 text-xs text-muted-foreground">
                At list price. The customer's tier and rebate are applied when the order is placed.
            </p>

            <FormField label="Notes" :error="form.errors.notes">
                <template #default="{ id }">
                    <Textarea :id="id" v-model="form.notes" placeholder="Add any notes for this order…" :rows="3" />
                </template>
            </FormField>

            <Disclosure title="Order settings" :summary="settingsSummary">
                <FormField label="Status" :error="form.errors.status">
                    <template #default="{ id }">
                        <Select :id="id" v-model="form.status" :options="STATUS_OPTIONS" />
                    </template>
                </FormField>

                <SwitchRow
                    v-model="form.is_backorder"
                    label="Backorder"
                    hint="Stock isn't available yet — reserve and fulfil later."
                />

                <SwitchRow
                    v-model="form.is_consignment"
                    label="Consignment (komisija)"
                    hint="Goods stay ours until the customer sells them."
                />

                <SwitchRow
                    v-model="form.shipping_paid_by_us"
                    label="We pay logistics"
                    hint="Freight is deducted from this order's margin only when we pay it."
                />

                <FormField
                    label="Shipping cost"
                    hint="Leave empty if there is no freight on this order."
                    :error="form.errors.shipping_cost"
                >
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.shipping_cost" type="number" step="0.01" min="0" placeholder="0,00" />
                    </template>
                </FormField>
            </Disclosure>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">Cancel</Button>
            <Button
                type="submit"
                form="create-order"
                :disabled="!canSubmit || form.processing"
            >
                Place order
            </Button>
        </template>
    </SidePanel>
</template>
