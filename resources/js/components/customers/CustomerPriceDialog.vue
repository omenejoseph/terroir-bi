<script setup lang="ts">
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import type { CustomerPriceRow } from '@/types/customers';
import type { ProductOption } from '@/types/orders';
import type { ComboboxOption } from '@/types/ui';

/**
 * "Add price" (Customer — Show · Pricing tab, Figma 231:9336). The frame only
 * draws the read-only list this tab already renders; the editing surface
 * itself is new, built against the same PATCH/DELETE shape the JSON API's
 * `inventory-items/{item}/customer-price/{customer}` already uses, via
 * Web\CustomerPriceController.
 *
 * `editing` set means adjusting an existing override — the product is fixed,
 * only the price changes. `editing` null means picking any sellable product
 * from the full catalogue to add a new one for.
 */
const props = defineProps<{
    open: boolean;
    customerId: string;
    /** The full sellable catalogue — undefined until this dialog first asks for it. */
    catalog: ProductOption[] | undefined;
    editing: CustomerPriceRow | null;
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({ inventory_item_id: null as string | null, price: '' });

/* Repopulate fresh on every open — a cancelled edit must not linger into the next one. */
watch(
    () => props.open,
    (open) => {
        if (!open) return;

        if (props.catalog === undefined) {
            router.reload({ only: ['pricingCatalog'] });
        }

        form.defaults({
            inventory_item_id: props.editing?.inventory_item_id ?? null,
            price: props.editing?.price ? String(props.editing.price.minor / 100) : '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const OPTIONS = computed<ComboboxOption[]>(() =>
    (props.catalog ?? []).map((product) => ({
        value: product.id,
        label: [product.name, product.vintage].filter(Boolean).join(' '),
        description: product.unit_size ?? undefined,
        keywords: [product.sku, product.vintage === null ? '' : String(product.vintage)].filter(Boolean) as string[],
    })),
);

function toMinor(value: string): number | null {
    if (value.trim() === '') return null;

    const n = Number.parseFloat(value.replace(',', '.'));

    return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : null;
}

const priceMinor = computed(() => toMinor(form.price));
const canSubmit = computed(() => form.inventory_item_id !== null && priceMinor.value !== null);

function submit(): void {
    const itemId = form.inventory_item_id;
    if (itemId === null || !canSubmit.value) return;

    form
        .transform(() => ({ price: priceMinor.value }))
        .patch(`/customers/${props.customerId}/prices/${itemId}`, {
            preserveScroll: true,
            // Stays a partial reload — see CustomerFormPanel.vue's submit()
            // for why a plain patch without `only` would silently drop every
            // other Optional prop this page may already have loaded.
            only: ['pricing'],
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <Dialog :open="open" :title="editing ? 'Edit price' : 'Add price'" @close="emit('close')">
        <div class="space-y-4">
            <FormField v-slot="{ id, invalid }" label="Product" :error="form.errors.inventory_item_id">
                <Combobox
                    v-if="!editing"
                    :id="id"
                    :model-value="form.inventory_item_id"
                    :invalid="invalid"
                    placeholder="Search product by name, SKU or vintage…"
                    empty-text="No product matches."
                    :options="OPTIONS"
                    @update:model-value="form.inventory_item_id = $event"
                />
                <p v-else class="text-sm font-medium">
                    {{ [editing.name, editing.vintage].filter(Boolean).join(' ') }}
                </p>
            </FormField>

            <FormField v-slot="{ id, invalid }" label="Price" :error="form.errors.price">
                <Input :id="id" v-model="form.price" :invalid="invalid" type="number" step="0.01" min="0" placeholder="0.00" />
            </FormField>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('close')">Cancel</Button>
            <Button :disabled="form.processing || !canSubmit" @click="submit">
                {{ form.processing ? 'Saving…' : 'Save price' }}
            </Button>
        </template>
    </Dialog>
</template>
