<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { InventoryItem, RecipeLine } from '@/types/inventory';

/**
 * Product Detail · Produce tab (Figma 449:1577): run a production batch off
 * the item's recipe (Recipe tab) — consumes each input, adds the output, in
 * one transaction (App\Actions\Inventory\ProduceItemAction), the same Action
 * the JSON API's own produce endpoint uses.
 */
const props = defineProps<{ item: InventoryItem; lines: RecipeLine[] }>();

const { t } = useTranslations();

const form = useForm({ display_quantity: '' });

const hasRecipe = computed(() => props.lines.length > 0);

function submit(): void {
    form.post(`/inventory/${props.item.id}/produce`, {
        preserveScroll: true,
        // See CustomerPriceDialog.vue's submit() for why a plain post without
        // `only` would silently drop every other Optional prop this page may
        // already have loaded. Producing changes the item's own stock and
        // writes movements, so both need to come back with it.
        only: ['item', 'analytics', 'movements'],
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <Callout v-if="!hasRecipe" :title="t('No recipe yet')">
            {{ t('Add a bill of materials on the Recipe tab before producing this item.') }}
        </Callout>

        <template v-else>
            <div class="flex flex-col divide-y divide-border border border-border text-sm">
                <div v-for="line in lines" :key="line.input_id ?? line.input_name" class="flex items-baseline justify-between gap-3 px-4 py-2.5">
                    <span class="min-w-0 truncate">{{ line.input_name }}</span>
                    <span class="shrink-0 text-xs text-muted-foreground">
                        {{ line.quantity }} {{ line.input_unit }}
                        <template v-if="line.input_stock"> · {{ t(':stock in stock', { stock: line.input_stock }) }}</template>
                    </span>
                </div>
            </div>

            <FormField v-slot="{ id, invalid }" :label="t('Quantity to produce')" :error="form.errors.display_quantity">
                <Input :id="id" v-model="form.display_quantity" :invalid="invalid" inputmode="decimal" placeholder="0" />
            </FormField>

            <Button class="self-start" :disabled="form.processing || form.display_quantity.trim() === ''" @click="submit">
                {{ form.processing ? t('Producing…') : t('Produce') }}
            </Button>
        </template>
    </div>
</template>
