<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';

import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Tabs from '@/components/ui/Tabs.vue';
import { useTranslations } from '@/composables/useTranslations';
import { WINE_TYPE_OPTIONS, parseDecimal, qty } from '@/lib/cellar';
import { csrfHeader } from '@/lib/csrf';
import type { TabItem } from '@/types/ui';

/**
 * The enological calculators, answered by the server (EnologicalCalculator,
 * So2Calculator, WineCompliance) so the numbers match everywhere else:
 * SO₂ with molecular SO₂, acid, a general addition, chaptalisation, and the
 * EU total-SO₂ limit check.
 */
const { t } = useTranslations();

type Kind = 'so2' | 'acid' | 'addition' | 'chaptalization' | 'so2_compliance';
const kind = ref<Kind>('so2');
const tabs = computed<TabItem[]>(() => [
    { label: t('SO₂'), value: 'so2' },
    { label: t('Acid'), value: 'acid' },
    { label: t('Addition'), value: 'addition' },
    { label: t('Chaptalisation'), value: 'chaptalization' },
    { label: t('EU SO₂ limit'), value: 'so2_compliance' },
]);

const FIELDS: Record<Kind, { key: string; label: string }[]> = {
    so2: [
        { key: 'volume_liters', label: 'Volume (L)' },
        { key: 'current_free_so2', label: 'Current free SO₂ (mg/L)' },
        { key: 'target_free_so2', label: 'Target free SO₂ (mg/L)' },
        { key: 'ph', label: 'pH' },
    ],
    acid: [
        { key: 'volume_liters', label: 'Volume (L)' },
        { key: 'current_ta', label: 'Current TA (g/L)' },
        { key: 'target_ta', label: 'Target TA (g/L)' },
    ],
    addition: [
        { key: 'volume_liters', label: 'Volume (L)' },
        { key: 'dose_mg_l', label: 'Dose (mg/L)' },
        { key: 'concentration_percent', label: 'Concentration (%) — optional' },
    ],
    chaptalization: [
        { key: 'volume_liters', label: 'Volume (L)' },
        { key: 'current_brix', label: 'Current Brix' },
        { key: 'target_brix', label: 'Target Brix' },
    ],
    so2_compliance: [
        { key: 'total_so2', label: 'Total SO₂ (mg/L)' },
        { key: 'residual_sugar', label: 'Residual sugar (g/L)' },
    ],
};

const values = reactive<Record<string, string>>({ ph: '3.5' });
const wineType = ref('RED');
const result = ref<Record<string, unknown> | null>(null);
const error = ref<string | null>(null);

let timer: ReturnType<typeof setTimeout> | null = null;
watch([kind, () => ({ ...values }), wineType], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(run, 300);
}, { deep: true });

async function run(): Promise<void> {
    const body: Record<string, unknown> = { kind: kind.value };
    for (const f of FIELDS[kind.value]) {
        const v = parseDecimal(values[f.key]);
        if (v === null) {
            if (f.key === 'concentration_percent' || f.key === 'residual_sugar') continue;
            result.value = null;

            return;
        }
        body[f.key] = v;
    }
    if (kind.value === 'so2_compliance') body.wine_type = wineType.value;
    const res = await fetch('/cellar/calculators', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeader() },
        body: JSON.stringify(body),
    });
    const json = await res.json();
    result.value = res.ok ? (json.data as Record<string, unknown>) : null;
    error.value = res.ok ? null : (Object.values((json.errors ?? {}) as Record<string, string[]>)[0]?.[0] ?? null);
}

const RESULT_LABELS: Record<string, string> = {
    grams_k2s2o5: 'K₂S₂O₅ (g)',
    molecular: 'Molecular SO₂ now (mg/L)',
    molecular_after: 'Molecular SO₂ after (mg/L)',
    grams_tartaric_acid: 'Tartaric acid (g)',
    grams_needed: 'Product needed (g)',
    kg_sugar: 'Sugar (kg)',
    potential_alcohol_increase: 'Potential alcohol increase (% vol)',
    limit: 'EU limit (mg/L)',
    margin: 'Margin to the limit (mg/L)',
};
</script>

<template>
    <div class="flex flex-col gap-4">
        <Tabs :items="tabs" :current="kind" @select="kind = $event as Kind" />
        <div class="grid gap-3 sm:grid-cols-4">
            <FormField v-for="f in FIELDS[kind]" :key="`${kind}-${f.key}`" v-slot="{ id }" :label="t(f.label)">
                <Input :id="id" v-model="values[f.key]" inputmode="decimal" />
            </FormField>
            <FormField v-if="kind === 'so2_compliance'" v-slot="{ id }" :label="t('Wine type')">
                <Select :id="id" v-model="wineType" :options="WINE_TYPE_OPTIONS.map((o) => ({ ...o, label: t(o.label) }))" />
            </FormField>
        </div>
        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
        <div v-if="result" class="flex flex-wrap gap-6 bg-muted px-4 py-3">
            <template v-for="(v, k) in result" :key="k">
                <div v-if="k === 'compliant'">
                    <p class="text-[10px] uppercase text-muted-foreground">{{ t('Compliant') }}</p>
                    <p :class="v ? 'text-lg font-semibold text-success' : 'text-lg font-semibold text-destructive'">{{ v ? t('Yes') : t('No') }}</p>
                </div>
                <div v-else-if="RESULT_LABELS[k as string]">
                    <p class="text-[10px] uppercase text-muted-foreground">{{ t(RESULT_LABELS[k as string]!) }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ qty(v as number) }}</p>
                </div>
            </template>
        </div>
    </div>
</template>
