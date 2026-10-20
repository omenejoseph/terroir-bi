<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Callout from '@/components/ui/Callout.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import { useTranslations } from '@/composables/useTranslations';
import { isWholeUnit, measureFactor } from '@/lib/stock';
import { formatQuantity } from '@/lib/money';
import type { SharedProps } from '@/types';
import type { InventoryItem, ProductionRun, RecipeLine } from '@/types/inventory';

/**
 * Product Detail · Produce tab (Figma 449:1577): run a production batch off
 * the item's recipe (Recipe tab) — consumes each input, adds the output, in
 * one transaction (App\Actions\Inventory\ProduceItemAction), the same Action
 * the JSON API's own produce endpoint uses.
 */
const props = defineProps<{ item: InventoryItem; lines: RecipeLine[]; runs: ProductionRun[] }>();

const { t } = useTranslations();
const page = usePage<SharedProps>();
const locale = computed(() => page.props.locale);

const form = useForm({ display_quantity: '' });

const hasRecipe = computed(() => props.lines.length > 0);

/** How much of a catalog input a run of the typed quantity takes, in the input's own unit; null until a quantity is typed. */
function needed(line: RecipeLine): number | null {
    const run = Number.parseFloat(form.display_quantity.replace(',', '.'));
    if (line.kind !== 'catalog' || !Number.isFinite(run) || run <= 0) return null;

    return Number.parseFloat(line.quantity) * (measureFactor(line.unit, line.input_unit) ?? 1) * run;
}

/** Short of what the run needs — flagged so the shortfall is seen before pressing Produce. */
function isShort(line: RecipeLine): boolean {
    const need = needed(line);

    return need !== null && line.input_stock !== null && Number.parseFloat(line.input_stock) < need;
}

function submit(): void {
    form.post(`/inventory/${props.item.id}/produce`, {
        preserveScroll: true,
        // See CustomerPriceDialog.vue's submit() for why a plain post without
        // `only` would silently drop every other Optional prop this page may
        // already have loaded. Producing changes the item's own stock and
        // writes movements, so both need to come back with it.
        only: ['item', 'analytics', 'movements', 'recipe', 'productionRuns'],
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
                    <!-- Opens the input, where its stock can be added from the Stock tab. -->
                    <Link v-if="line.input_id" :href="`/inventory/${line.input_id}`" class="min-w-0 truncate underline-offset-2 hover:underline">
                        {{ line.input_name }}
                    </Link>
                    <span v-else class="min-w-0 truncate">{{ line.input_name }}</span>
                    <span class="shrink-0 text-xs text-muted-foreground" :class="isShort(line) && 'text-destructive'">
                        {{ line.quantity }} {{ line.unit ?? line.input_unit }}
                        <template v-if="line.kind !== 'catalog'"> · {{ t('cost only') }}</template>
                        <template v-if="line.input_stock"> · {{ t(':stock in stock', { stock: line.input_stock }) }}</template>
                        <template v-if="isShort(line)"> · {{ t('short — add stock') }}</template>
                    </span>
                </div>
            </div>

            <FormField v-slot="{ id, invalid }" :label="t('Quantity to produce')" :error="form.errors.display_quantity">
                <Input
                    :id="id"
                    v-model="form.display_quantity"
                    :invalid="invalid"
                    :inputmode="isWholeUnit(item.unit) ? 'numeric' : 'decimal'"
                    placeholder="0"
                    @input="isWholeUnit(item.unit) && (form.display_quantity = form.display_quantity.replace(/[^0-9]/g, ''))"
                />
            </FormField>

            <Button class="self-start" :disabled="form.processing || form.display_quantity.trim() === ''" @click="submit">
                {{ form.processing ? t('Producing…') : t('Produce') }}
            </Button>
        </template>

        <!-- What has been produced, newest first -->
        <section class="flex flex-col gap-2">
            <h3 class="text-sm font-semibold">{{ t('Production history') }}</h3>
            <p v-if="!runs.length" class="text-xs text-muted-foreground">{{ t('Nothing produced yet.') }}</p>
            <div v-else class="flex flex-col divide-y divide-border border border-border text-sm">
                <div v-for="run in runs" :key="run.id" class="flex flex-col gap-1 px-4 py-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="font-medium tabular-nums">+{{ formatQuantity(run.quantity, locale) }} {{ item.unit }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ new Date(run.at).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) }}<template v-if="run.by"> · {{ run.by }}</template>
                        </span>
                    </div>
                    <p v-if="run.inputs?.length" class="text-xs text-muted-foreground">
                        {{ t('Used') }}:
                        {{ run.inputs.map((i) => `${formatQuantity(i.quantity, locale)} ${i.unit} ${i.name}`).join(' · ') }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
