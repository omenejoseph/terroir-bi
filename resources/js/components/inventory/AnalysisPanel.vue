<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import type { BottleAnalysisRow, InventoryItem } from '@/types/inventory';

/**
 * Product Detail · Analysis tab (Figma 449:1577): lab/enology measurements
 * recorded against this wine over time — the same StoreBottleAnalysisRequest
 * Api\BottleAnalysisController uses. Only the date is required; every
 * measurement is independently optional, matching the request's own rules.
 */
const props = defineProps<{ item: InventoryItem; analyses: BottleAnalysisRow[] }>();

const { t } = useTranslations();

type MeasurementKey =
    | 'ph'
    | 'total_acidity'
    | 'volatile_acidity'
    | 'alcohol'
    | 'residual_sugar'
    | 'free_so2'
    | 'total_so2'
    | 'temperature'
    | 'density'
    | 'tpi';

/** Label + unit, in the order the design's measurement fields read. */
const MEASUREMENTS: { key: MeasurementKey; label: string; unit: string }[] = [
    { key: 'ph', label: t('pH'), unit: '' },
    { key: 'total_acidity', label: t('Total acidity'), unit: 'g/L' },
    { key: 'volatile_acidity', label: t('Volatile acidity'), unit: 'g/L' },
    { key: 'alcohol', label: t('Alcohol'), unit: '%' },
    { key: 'residual_sugar', label: t('Residual sugar'), unit: 'g/L' },
    { key: 'free_so2', label: t('Free SO₂'), unit: 'mg/L' },
    { key: 'total_so2', label: t('Total SO₂'), unit: 'mg/L' },
    { key: 'temperature', label: t('Temperature'), unit: '°C' },
    { key: 'density', label: t('Density'), unit: '' },
    { key: 'tpi', label: t('TPI'), unit: '' },
];

const form = useForm({
    analyzed_on: new Date().toISOString().slice(0, 10),
    ph: '',
    total_acidity: '',
    volatile_acidity: '',
    alcohol: '',
    residual_sugar: '',
    free_so2: '',
    total_so2: '',
    temperature: '',
    density: '',
    tpi: '',
    note: '',
});

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            ...Object.fromEntries(MEASUREMENTS.map((m) => [m.key, data[m.key] || null])),
        }))
        .post(`/inventory/${props.item.id}/bottle-analyses`, {
            preserveScroll: true,
            only: ['bottleAnalyses'],
            onSuccess: () => form.reset(),
        });
}

async function remove(analysis: BottleAnalysisRow): Promise<void> {
    const ok = await confirmDialog({
        title: t('Remove analysis'),
        description: t('Remove the analysis from :date? This cannot be undone.', { date: analysis.analyzed_on }),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/inventory/${props.item.id}/bottle-analyses/${analysis.id}`, {
        preserveScroll: true,
        only: ['bottleAnalyses'],
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <FormField v-slot="{ id, invalid }" :label="t('Date')" required :error="form.errors.analyzed_on">
                <Input :id="id" v-model="form.analyzed_on" type="date" :invalid="invalid" class="max-w-48" />
            </FormField>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <FormField
                    v-for="measurement in MEASUREMENTS"
                    :key="measurement.key"
                    v-slot="{ id, invalid }"
                    :label="measurement.unit ? `${measurement.label} (${measurement.unit})` : measurement.label"
                    :error="form.errors[measurement.key]"
                >
                    <Input :id="id" v-model="form[measurement.key]" :invalid="invalid" inputmode="decimal" placeholder="—" />
                </FormField>
            </div>

            <FormField v-slot="{ id }" :label="t('Note')" :error="form.errors.note">
                <Textarea :id="id" v-model="form.note" :rows="2" :placeholder="t('e.g. Pre-bottling')" />
            </FormField>

            <Button type="submit" class="self-start" :disabled="form.processing">
                {{ form.processing ? t('Saving…') : t('Record analysis') }}
            </Button>
        </form>

        <div class="flex flex-col gap-3">
            <h3 class="text-sm font-semibold text-foreground">{{ t('History') }}</h3>

            <div v-if="analyses.length" class="overflow-x-auto border border-border">
                <table class="w-full min-w-[52rem] text-xs">
                    <thead class="border-b border-border bg-muted/40 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">{{ t('Date') }}</th>
                            <th v-for="measurement in MEASUREMENTS" :key="measurement.key" scope="col" class="px-3 py-2 text-right font-medium">
                                {{ measurement.label }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ t('Note') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium"><span class="sr-only">{{ t('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="analysis in analyses" :key="analysis.id" class="border-b border-border last:border-b-0">
                            <td class="px-3 py-2 whitespace-nowrap">{{ analysis.analyzed_on }}</td>
                            <td
                                v-for="measurement in MEASUREMENTS"
                                :key="measurement.key"
                                class="px-3 py-2 text-right tabular-nums text-muted-foreground"
                            >
                                {{ analysis[measurement.key] ?? '—' }}
                            </td>
                            <td class="max-w-40 truncate px-3 py-2 text-muted-foreground">{{ analysis.note ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <button
                                    type="button"
                                    class="p-1 text-muted-foreground transition-colors hover:text-destructive"
                                    :aria-label="t('Remove the analysis from :date', { date: analysis.analyzed_on })"
                                    @click="remove(analysis)"
                                >
                                    <Trash2 class="size-3.5" :stroke-width="1.5" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-xs text-muted-foreground">{{ t('No analyses recorded yet.') }}</p>
        </div>
    </div>
</template>
