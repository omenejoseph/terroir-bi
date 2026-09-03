<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Camera, Plus } from 'lucide-vue-next';

import OrderLineFields from '@/components/orders/OrderLineFields.vue';
import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Disclosure from '@/components/ui/Disclosure.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import SwitchRow from '@/components/ui/SwitchRow.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { linesToPayload } from '@/lib/orders';
import { formatMoney } from '@/lib/money';
import type { OrderLineDraft, ProductOption } from '@/types/orders';
import type { ComboboxOption } from '@/types/ui';
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

const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);
const { t } = useTranslations();

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
            // On the Orders page this panel can be mounted alongside the Order
            // — View drawer, which keys its own `order` prop off `?order=`.
            // A plain reload preserves the current URL, but re-sending that
            // query param explicitly (harmless if absent) keeps this from
            // ever being the one to drop it.
            const orderId = page.props.order as { id?: string } | null | undefined;

            router.reload({
                only: ['customerOptions', 'productOptions'],
                data: orderId?.id ? { order: orderId.id } : {},
            });
        }
    },
);

const lines = ref<OrderLineDraft[]>([]);

const form = useForm({
    customer_id: '',
    notes: '',
    status: 'RECEIVED',
    is_backorder: false,
    is_consignment: false,
    shipping_cost: '',
    shipping_paid_by_us: true,
    items: [] as ReturnType<typeof linesToPayload>,
});

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

const CUSTOMER_OPTIONS = computed<ComboboxOption[]>(() =>
    customers.value.map((c) => ({
        value: c.id,
        label: c.company_name,
        description: c.city ?? undefined,
        keywords: [c.city, c.customer_type].filter(Boolean) as string[],
    })),
);

const STATUS_OPTIONS = computed(() => [
    { value: 'RECEIVED', label: t('Received') },
    { value: 'IN_PROCESS', label: t('In Process') },
    { value: 'READY_TO_SHIP', label: t('Ready to Ship') },
    { value: 'SHIPPED', label: t('Shipped') },
]);

/** The collapsed disclosure summarises what it hides (Figma 335:4233). */
const settingsSummary = computed(() =>
    [
        STATUS_OPTIONS.value.find((s) => s.value === form.status)?.label ?? form.status,
        form.is_backorder ? t('Backorder') : t('Not backorder'),
        form.is_consignment ? t('Consignment') : null,
        form.shipping_paid_by_us ? t('We pay logistics') : t('Customer pays logistics'),
    ]
        .filter(Boolean)
        .join(' · '),
);

function submit(): void {
    form.items = linesToPayload(lines.value);

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
    <SidePanel :open="open" :title="t('Create order')" @close="emit('close')">
        <form id="create-order" class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField :label="t('Customer')" required :error="form.errors.customer_id">
                <template #default="{ id, invalid }">
                    <div class="flex items-center gap-2">
                        <Combobox
                            :id="id"
                            :model-value="form.customer_id === '' ? null : form.customer_id"
                            :invalid="invalid"
                            :placeholder="t('Select a customer…')"
                            :empty-text="t('No customer matches.')"
                            :options="CUSTOMER_OPTIONS"
                            class="flex-1"
                            @update:model-value="form.customer_id = $event ?? ''"
                        />
                        <!-- @todo New customer inline. The design opens the
                             Customer — Create drawer from here; that drawer
                             arrives with Phase 4. -->
                        <button
                            type="button"
                            class="inline-flex size-9 shrink-0 items-center justify-center border border-border text-muted-foreground hover:border-foreground/40 hover:text-foreground"
                            :aria-label="t('New customer')"
                        >
                            <Plus class="size-4" :stroke-width="1.5" />
                        </button>
                    </div>
                </template>
            </FormField>

            <section class="flex flex-col gap-3">
                <span class="text-sm leading-5 font-medium text-foreground">
                    {{ t('Products') }}<span class="ml-1 text-destructive" aria-hidden="true">*</span>
                </span>

                <OrderLineFields v-model="lines" :products="products" :locale="locale">
                    <template #actions>
                        <!-- @todo Import screenshot. The design offers reading an
                             order off a photo; there is no such endpoint. -->
                        <Button variant="outline" size="sm" type="button">
                            <Camera class="size-3.5" :stroke-width="1.5" />
                            {{ t('Import screenshot') }}
                        </Button>
                    </template>
                </OrderLineFields>

                <p v-if="form.errors.items" class="text-xs text-destructive" role="alert">{{ form.errors.items }}</p>
            </section>

            <div class="flex items-baseline justify-between gap-3 border-t border-border pt-4">
                <span class="text-sm">{{ t('Subtotal · excl. VAT') }}</span>
                <span class="text-lg font-semibold tabular-nums">
                    {{ formatMoney(subtotal, currency, locale) }}
                </span>
            </div>
            <p class="-mt-3 text-xs text-muted-foreground">
                {{ t("At list price. The customer's tier and rebate are applied when the order is placed.") }}
            </p>

            <FormField :label="t('Notes')" :error="form.errors.notes">
                <template #default="{ id }">
                    <Textarea :id="id" v-model="form.notes" :placeholder="t('Add any notes for this order…')" :rows="3" />
                </template>
            </FormField>

            <Disclosure :title="t('Order settings')" :summary="settingsSummary">
                <FormField :label="t('Status')" :error="form.errors.status">
                    <template #default="{ id }">
                        <Select :id="id" v-model="form.status" :options="STATUS_OPTIONS" />
                    </template>
                </FormField>

                <SwitchRow
                    v-model="form.is_backorder"
                    :label="t('Backorder')"
                    :hint="t('Stock isn\'t available yet — reserve and fulfil later.')"
                />

                <SwitchRow
                    v-model="form.is_consignment"
                    :label="t('Consignment (komisija)')"
                    :hint="t('Goods stay ours until the customer sells them.')"
                />

                <SwitchRow
                    v-model="form.shipping_paid_by_us"
                    :label="t('We pay logistics')"
                    :hint="t('Freight is deducted from this order\'s margin only when we pay it.')"
                />

                <FormField
                    :label="t('Shipping cost')"
                    :hint="t('Leave empty if there is no freight on this order.')"
                    :error="form.errors.shipping_cost"
                >
                    <template #default="{ id }">
                        <Input :id="id" v-model="form.shipping_cost" type="number" step="0.01" min="0" :placeholder="t('0,00')" />
                    </template>
                </FormField>
            </Disclosure>
        </form>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button
                type="submit"
                form="create-order"
                :disabled="!canSubmit || form.processing"
            >
                {{ t('Place order') }}
            </Button>
        </template>
    </SidePanel>
</template>
