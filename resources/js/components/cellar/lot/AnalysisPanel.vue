<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS, WINE_TYPE_LABELS, molecularSo2, parseDecimal, so2ReferenceTarget } from '@/lib/cellar';
import type { AnalysisParam, AnalysisRow, LotRef } from '@/types/cellar';

/**
 * Record or correct an analysis: any of the 14 parameters, typed with a comma
 * or a point. Brix and Baumé convert into each other as you type.
 */
const props = defineProps<{ open: boolean; lot: LotRef; record: AnalysisRow | null; vesselId?: string | null }>();
const emit = defineEmits<{ close: []; saved: [] }>();

const { t } = useTranslations();

const values = ref<Record<AnalysisParam, string>>({} as Record<AnalysisParam, string>);
const baume = ref('');
const form = useForm({ date: '', vessel_id: '', note: '' });

watch(
    () => [props.open, props.record?.id],
    () => {
        if (!props.open) return;
        const r = props.record;
        values.value = Object.fromEntries(ANALYSIS_FIELDS.map((f) => [f.key, r?.[f.key] ?? ''])) as Record<AnalysisParam, string>;
        baume.value = '';
        form.defaults({ date: (r?.date ?? new Date().toISOString()).slice(0, 10), vessel_id: r?.vessel_id ?? props.vesselId ?? '', note: r?.note ?? '' });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

/** Baumé ≈ Brix ÷ 1.8 (the prototype's conversion). */
let typingBaume = false;
function fromBaume(): void {
    const b = parseDecimal(baume.value);
    if (b === null) return;
    typingBaume = true;
    values.value.brix = String(Math.round(b * 1.8 * 10) / 10);
}
watch(() => values.value.brix, (v) => {
    if (typingBaume) {
        typingBaume = false;

        return;
    }
    const b = parseDecimal(v);
    if (b !== null) baume.value = String(Math.round((b / 1.8) * 10) / 10);
});

const vesselOptions = computed(() => [{ value: '', label: t('Whole lot') }, ...props.lot.vessels.map((v) => ({ value: v.vessel_id, label: v.vessel_name ?? '—' }))]);
/** A free-SO₂ reading below the wine's reference target says so, and links to planning the correction. */
const so2Hint = computed(() => {
    const free = parseDecimal(values.value.free_so2);
    if (free === null || props.record || props.lot.wine_type === undefined) return null;
    const target = so2ReferenceTarget(props.lot.wine_type);
    if (free >= target) return null;

    return { free, target, molecular: molecularSo2(free, parseDecimal(values.value.ph)) };
});
const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function submit(): void {
    form.transform((d) => ({
        date: d.date || null,
        vessel_id: d.vessel_id || null,
        note: d.note || null,
        ...Object.fromEntries(ANALYSIS_FIELDS.map((f) => [f.key, parseDecimal(values.value[f.key])])),
    }));
    const url = `/cellar/lots/${props.lot.id}/analyses`;
    if (props.record) form.patch(`${url}/${props.record.id}`, { preserveScroll: true, onSuccess: () => { emit('saved'); emit('close'); } });
    else form.post(url, { preserveScroll: true, onSuccess: () => { emit('saved'); emit('close'); } });
}
</script>

<template>
    <SidePanel :open="open" :title="record ? t('Edit analysis') : t('New analysis')" :subtitle="lot.name" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <div class="grid grid-cols-2 gap-3">
                <FormField v-slot="{ id, invalid }" :label="t('Date')" :error="form.errors.date"><Input :id="id" :invalid="invalid" v-model="form.date" type="date" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Vessel')" :error="form.errors.vessel_id"><Combobox :id="id" :invalid="invalid" v-model="form.vessel_id" :options="vesselOptions" :empty-text="t('No vessel matches.')" /></FormField>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <FormField v-slot="{ id, invalid }" v-for="f in ANALYSIS_FIELDS" :key="f.key" :label="t(f.label)" :error="err(f.key)">
                    <Input :id="id" :invalid="invalid" v-model="values[f.key]" inputmode="decimal" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Baumé')"><Input :id="id" :invalid="invalid" v-model="baume" inputmode="decimal" @input="fromBaume" /></FormField>
            </div>
            <div v-if="so2Hint" class="flex flex-col gap-1 bg-tag-orange px-3 py-2 text-xs text-tag-orange-foreground" role="status">
                <p>
                    {{ t('Free SO₂ :free is below the :type target of :target mg/L.', { free: so2Hint.free, type: t(WINE_TYPE_LABELS[lot.wine_type as keyof typeof WINE_TYPE_LABELS] ?? 'wine').toLowerCase(), target: so2Hint.target }) }}
                    <template v-if="so2Hint.molecular !== null">{{ t('Molecular :m.', { m: so2Hint.molecular }) }}</template>
                </p>
                <Link :href="`/cellar/so2?lots=${lot.id}`" class="font-medium underline underline-offset-2">{{ t('Plan SO₂ for this wine →') }}</Link>
            </div>
            <FormField v-slot="{ id, invalid }" :label="t('Note')" :error="form.errors.note"><Textarea :id="id" :invalid="invalid" v-model="form.note" :rows="2" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
