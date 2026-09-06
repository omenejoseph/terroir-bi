<script setup lang="ts">
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { ItemTierPriceRow } from '@/types/inventory';
import type { PricingTierSummary } from '@/types/customers';
import type { ComboboxOption } from '@/types/ui';

/**
 * "Add tier price" (Product Detail · Pricing tab, Figma 449:1577). The item
 * is fixed; picks a pricing tier rather than a product — the mirror image of
 * CustomerPriceDialog.vue, which fixes the customer and picks a product.
 * Same PATCH/DELETE shape, via Web\TierPriceController.
 */
const props = defineProps<{
    open: boolean;
    itemId: string;
    /** Every tenant pricing tier — undefined until this dialog first asks for it. */
    tiers: PricingTierSummary[] | undefined;
    editing: ItemTierPriceRow | null;
}>();

const emit = defineEmits<{ close: [] }>();
const { t } = useTranslations();

const form = useForm({ pricing_tier_id: null as string | null, price: '' });

/* Repopulate fresh on every open — a cancelled edit must not linger into the next one. */
watch(
    () => props.open,
    (open) => {
        if (!open) return;

        if (props.tiers === undefined) {
            router.reload({ only: ['pricingTierOptions'] });
        }

        form.defaults({
            pricing_tier_id: props.editing?.pricing_tier_id ?? null,
            price: props.editing?.price ? String(props.editing.price.minor / 100) : '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const OPTIONS = computed<ComboboxOption[]>(() =>
    (props.tiers ?? []).map((tier) => ({
        value: tier.id,
        label: tier.name,
        description: t(':percent% rebate', { percent: tier.rebate_percent }),
    })),
);

function toMinor(value: string): number | null {
    if (value.trim() === '') return null;

    const n = Number.parseFloat(value.replace(',', '.'));

    return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : null;
}

const priceMinor = computed(() => toMinor(form.price));
const canSubmit = computed(() => form.pricing_tier_id !== null && priceMinor.value !== null);

function submit(): void {
    const tierId = form.pricing_tier_id;
    if (tierId === null || !canSubmit.value) return;

    form
        .transform(() => ({ price: priceMinor.value }))
        .patch(`/inventory/${props.itemId}/tier-prices/${tierId}`, {
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
    <Dialog :open="open" :title="editing ? t('Edit tier price') : t('Add tier price')" @close="emit('close')">
        <div class="space-y-4">
            <FormField v-slot="{ id, invalid }" :label="t('Pricing tier')" :error="form.errors.pricing_tier_id">
                <Combobox
                    v-if="!editing"
                    :id="id"
                    :model-value="form.pricing_tier_id"
                    :invalid="invalid"
                    :placeholder="t('Search pricing tiers…')"
                    :empty-text="t('No tier matches.')"
                    :options="OPTIONS"
                    @update:model-value="form.pricing_tier_id = $event"
                />
                <p v-else class="text-sm font-medium">{{ editing.tier_name }}</p>
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
