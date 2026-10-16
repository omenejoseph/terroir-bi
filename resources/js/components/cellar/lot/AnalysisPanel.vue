<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import { ANALYSIS_FIELDS, parseDecimal } from '@/lib/cellar';
import type { AnalysisParam, AnalysisRow, WineLotDetail } from '@/types/cellar';

/**
 * Record or correct an analysis: any of the 14 parameters, typed with a comma
 * or a point. Brix and Baumé convert into each other as you type.
 */
const props = defineProps<{ open: boolean; lot: WineLotDetail; record: AnalysisRow | null; vesselId?: string | null }>();
const emit = defineEmits<{ close: [] }>();

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
const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

function submit(): void {
    form.transform((d) => ({
        date: d.date || null,
        vessel_id: d.vessel_id || null,
        note: d.note || null,
        ...Object.fromEntries(ANALYSIS_FIELDS.map((f) => [f.key, parseDecimal(values.value[f.key])])),
    }));
    const url = `/cellar/lots/${props.lot.id}/analyses`;
    if (props.record) form.patch(`${url}/${props.record.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
    else form.post(url, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="record ? t('Edit analysis') : t('New analysis')" :subtitle="lot.name" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <div class="grid grid-cols-2 gap-3">
                <FormField v-slot="{ id, invalid }" :label="t('Date')" :error="form.errors.date"><Input :id="id" :invalid="invalid" v-model="form.date" type="date" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Vessel')" :error="form.errors.vessel_id"><Select :id="id" :invalid="invalid" v-model="form.vessel_id" :options="vesselOptions" /></FormField>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <FormField v-slot="{ id, invalid }" v-for="f in ANALYSIS_FIELDS" :key="f.key" :label="t(f.label)" :error="err(f.key)">
                    <Input :id="id" :invalid="invalid" v-model="values[f.key]" inputmode="decimal" />
                </FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Baumé')"><Input :id="id" :invalid="invalid" v-model="baume" inputmode="decimal" @input="fromBaume" /></FormField>
            </div>
            <FormField v-slot="{ id, invalid }" :label="t('Note')" :error="form.errors.note"><Textarea :id="id" :invalid="invalid" v-model="form.note" :rows="2" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
