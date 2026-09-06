<script setup lang="ts">
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { ItemCustomerPriceRow } from '@/types/inventory';
import type { ComboboxOption } from '@/types/ui';

/**
 * "Add customer price" (Product Detail · Pricing tab, Figma 449:1577). The
 * item is fixed; picks a customer rather than a product — the mirror image
 * of CustomerPriceDialog.vue (Customer — Show's own Pricing tab), which
 * fixes the customer and picks a product. Same PATCH/DELETE shape, via
 * Web\CustomerPriceController — the route is customer+item either way.
 */
interface CustomerOption {
    id: string;
    company_name: string;
}

const props = defineProps<{
    open: boolean;
    itemId: string;
    /** Every active customer — undefined until this dialog first asks for it. */
    customers: CustomerOption[] | undefined;
    editing: ItemCustomerPriceRow | null;
}>();

const emit = defineEmits<{ close: [] }>();
const { t } = useTranslations();

const form = useForm({ customer_id: null as string | null, price: '' });

/* Repopulate fresh on every open — a cancelled edit must not linger into the next one. */
watch(
    () => props.open,
    (open) => {
        if (!open) return;

        if (props.customers === undefined) {
            router.reload({ only: ['pricingCustomerOptions'] });
        }

        form.defaults({
            customer_id: props.editing?.customer_id ?? null,
            price: props.editing?.price ? String(props.editing.price.minor / 100) : '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const OPTIONS = computed<ComboboxOption[]>(() =>
    (props.customers ?? []).map((customer) => ({ value: customer.id, label: customer.company_name })),
);

function toMinor(value: string): number | null {
    if (value.trim() === '') return null;

    const n = Number.parseFloat(value.replace(',', '.'));

    return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : null;
}

const priceMinor = computed(() => toMinor(form.price));
const canSubmit = computed(() => form.customer_id !== null && priceMinor.value !== null);

function submit(): void {
    const customerId = form.customer_id;
    if (customerId === null || !canSubmit.value) return;

    form
        .transform(() => ({ price: priceMinor.value }))
        .patch(`/customers/${customerId}/prices/${props.itemId}`, {
            preserveScroll: true,
            // See CustomerPriceDialog.vue's submit() for why a plain patch
            // without `only` would silently drop every other Optional prop
            // this page may already have loaded.
            only: ['pricing'],
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <Dialog :open="open" :title="editing ? t('Edit customer price') : t('Add customer price')" @close="emit('close')">
        <div class="space-y-4">
            <FormField v-slot="{ id, invalid }" :label="t('Customer')" :error="form.errors.customer_id">
                <Combobox
                    v-if="!editing"
                    :id="id"
                    :model-value="form.customer_id"
                    :invalid="invalid"
                    :placeholder="t('Search customers…')"
                    :empty-text="t('No customer matches.')"
                    :options="OPTIONS"
                    @update:model-value="form.customer_id = $event"
                />
                <p v-else class="text-sm font-medium">{{ editing.company_name }}</p>
            </FormField>

            <FormField v-slot="{ id, invalid }" :label="t('Price')" :error="form.errors.price">
                <Input :id="id" v-model="form.price" :invalid="invalid" type="number" step="0.01" min="0" placeholder="0.00" />
            </FormField>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button :disabled="form.processing || !canSubmit" @click="submit">
                {{ form.processing ? t('Saving…') : t('Save price') }}
            </Button>
        </template>
    </Dialog>
</template>
