<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import { useTranslations } from '@/composables/useTranslations';

/**
 * "Quick stock entry" (Figma `449:1577`) — records one ledger movement without
 * leaving the page.
 *
 * The quantity is entered unsigned and signed here from the movement type, so
 * "Stock Out 5" cannot be recorded as +5 by mistake. Marking an entry a
 * correction sets `is_reconciliation`, which excludes it from velocity and
 * cover exactly as the design's helper text promises.
 */
const props = defineProps<{
    itemId: string;
    unit: string;
    /**
     * Restricts the follow-up reload to these props. Product Detail's own
     * props are all eager-loaded, so a full reload there is harmless and this
     * is left undefined; a host with `Inertia::optional` props (the Item —
     * View drawer's `itemMovements`) must pass its own, or a bare `.post()`
     * silently drops them — see OrderViewPanel.vue's reloadOrder() for the
     * same gotcha.
     */
    only?: string[];
}>();
const { t } = useTranslations();

const form = useForm({
    type: 'MANUAL_IN',
    quantity: '',
    note: '',
    is_reconciliation: false,
});

const TYPES = [
    { value: 'MANUAL_IN', label: t('Stock In') },
    { value: 'MANUAL_OUT', label: t('Stock Out') },
    { value: 'ADJUSTMENT', label: t('Adjustment') },
];

const isOutbound = computed(() => form.type === 'MANUAL_OUT');

function submit(): void {
    form
        .transform((data) => {
            const magnitude = Math.abs(Number.parseFloat(String(data.quantity)) || 0);

            return {
                ...data,
                quantity: String(isOutbound.value ? -magnitude : magnitude),
                note: data.note === '' ? null : data.note,
            };
        })
        .post(`/inventory/${props.itemId}/stock`, {
            preserveScroll: true,
            only: props.only,
            onSuccess: () => form.reset(),
        });
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,10rem)_minmax(0,10rem)_1fr_auto] sm:items-end">
            <FormField v-slot="{ id, invalid }" :label="t('Type')" :error="form.errors.type">
                <Select :id="id" v-model="form.type" :invalid="invalid" :options="TYPES" />
            </FormField>

            <FormField v-slot="{ id, invalid }" :label="t('Quantity (:unit)', { unit })" :error="form.errors.quantity">
                <Input :id="id" v-model="form.quantity" :invalid="invalid" placeholder="0" inputmode="decimal" />
            </FormField>

            <FormField v-slot="{ id, invalid }" :label="t('Note')" :error="form.errors.note">
                <Input :id="id" v-model="form.note" :invalid="invalid" :placeholder="t('Optional note…')" />
            </FormField>

            <Button type="submit" :disabled="form.processing || form.quantity === ''">
                {{ form.processing ? t('Adding…') : t('Add') }}
            </Button>
        </div>

        <Checkbox
            v-model="form.is_reconciliation"
            :label="t('Inventory-count correction — won\'t count as a sale or exit')"
            :hint="t('Use when matching the book to a physical count. Corrections are excluded from velocity and cover.')"
        />
    </form>
</template>
