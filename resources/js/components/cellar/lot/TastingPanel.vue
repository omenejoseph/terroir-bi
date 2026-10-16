<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import FieldRow from '@/components/ui/FieldRow.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import SidePanel from '@/components/ui/SidePanel.vue';
import Textarea from '@/components/ui/Textarea.vue';
import { useTranslations } from '@/composables/useTranslations';
import type { WineLotDetail } from '@/types/cellar';

/** Add or correct a tasting note (appearance, nose, palate, overall, score /100) for the lot or one vessel. */
type Note = WineLotDetail['tasting_notes'][number];

const props = defineProps<{ open: boolean; lotId: string; lotName: string; vessels: { vessel_id: string; vessel_name: string | null }[]; record: Note | null; vesselId?: string | null }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useTranslations();

const form = useForm({ date: '', vessel_id: '', score: '', appearance: '', nose: '', palate: '', overall: '', note: '' });

watch(
    () => [props.open, props.record?.id],
    () => {
        if (!props.open) return;
        const r = props.record;
        form.defaults({
            date: (r?.date ?? new Date().toISOString()).slice(0, 10),
            vessel_id: r?.vessel_id ?? props.vesselId ?? '',
            score: r?.score !== null && r?.score !== undefined ? String(r.score) : '',
            appearance: r?.appearance ?? '',
            nose: r?.nose ?? '',
            palate: r?.palate ?? '',
            overall: r?.overall ?? '',
            note: r?.note ?? '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const vesselOptions = computed(() => [{ value: '', label: t('Whole lot') }, ...props.vessels.map((v) => ({ value: v.vessel_id, label: v.vessel_name ?? '—' }))]);

function submit(): void {
    form.transform((d) => ({
        date: d.date || null,
        vessel_id: d.vessel_id || null,
        score: d.score === '' ? null : Number(d.score),
        appearance: d.appearance || null,
        nose: d.nose || null,
        palate: d.palate || null,
        overall: d.overall || null,
        note: d.note || null,
    }));
    const url = `/cellar/lots/${props.lotId}/tasting-notes`;
    if (props.record) form.patch(`${url}/${props.record.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
    else form.post(url, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <SidePanel :open="open" :title="record ? t('Edit tasting note') : t('New tasting note')" :subtitle="lotName" @close="emit('close')">
        <div class="flex flex-col gap-4">
            <FieldRow>
                <FormField v-slot="{ id, invalid }" :label="t('Date')" :error="form.errors.date"><Input :id="id" :invalid="invalid" v-model="form.date" type="date" /></FormField>
                <FormField v-slot="{ id, invalid }" :label="t('Score (/100)')" :error="form.errors.score"><Input :id="id" :invalid="invalid" v-model="form.score" inputmode="numeric" /></FormField>
            </FieldRow>
            <FormField v-slot="{ id, invalid }" :label="t('Vessel')" :error="form.errors.vessel_id"><Select :id="id" :invalid="invalid" v-model="form.vessel_id" :options="vesselOptions" /></FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Appearance')" :error="form.errors.appearance"><Input :id="id" :invalid="invalid" v-model="form.appearance" /></FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Nose')" :error="form.errors.nose"><Input :id="id" :invalid="invalid" v-model="form.nose" /></FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Palate')" :error="form.errors.palate"><Input :id="id" :invalid="invalid" v-model="form.palate" /></FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Overall')" :error="form.errors.overall"><Input :id="id" :invalid="invalid" v-model="form.overall" /></FormField>
            <FormField v-slot="{ id, invalid }" :label="t('Note')" :error="form.errors.note"><Textarea :id="id" :invalid="invalid" v-model="form.note" :rows="2" /></FormField>
        </div>
        <template #footer>
            <Button variant="outline" size="lg" @click="emit('close')">{{ t('Cancel') }}</Button>
            <Button size="lg" :disabled="form.processing" @click="submit">{{ t('Save') }}</Button>
        </template>
    </SidePanel>
</template>
